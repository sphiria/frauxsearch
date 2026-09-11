local operation = ARGV[1]
local maximum = 9007199254740991
local batchSize = 500

local function member(page, id)
    return string.format('%016.0f:%016.0f', page, id)
end

local function problem(message)
    return { 'error', message }
end

local function operationId(value)
    return type(value) == 'string' and #value == 32 and string.match(value, '^[a-f0-9]+$') ~= nil
end

local function indexName(value)
    return type(value) == 'string' and string.match(value, '^[A-Za-z0-9_-]+$') ~= nil
end

local function integer(value)
    return type(value) == 'number' and value >= 0 and value <= maximum and value == math.floor(value)
end

local function validState(state)
    if type(state) ~= 'table' or state.version ~= 2 or not operationId(state.epoch)
        or type(state.closing) ~= 'boolean' or state.run == nil or state.pending == nil then return false end
    local run = state.run
    if run ~= cjson.null then
        if type(run) ~= 'table' or not operationId(run.id) or type(run.recovery) ~= 'boolean'
            or state.closing or not integer(run.watermark) or not indexName(run.completion)
            or (run.full ~= cjson.null and not indexName(run.full)) or run.full == run.completion then return false end
        local phases = { building = true, ready = true, catchup = true, swapping = true,
            activated = true, aborting = true }
        if type(run.phase) ~= 'string' or not phases[run.phase] then return false end
    end
    local pending = state.pending
    if pending == cjson.null then return true end
    if type(pending) ~= 'table' or not operationId(pending.id)
        or (pending.purpose ~= 'write' and pending.purpose ~= 'guard-delete')
        or state.closing ~= (pending.purpose == 'guard-delete')
        or (pending.method ~= 'POST' and pending.method ~= 'PATCH' and pending.method ~= 'DELETE')
        or type(pending.path) ~= 'string' or string.sub(pending.path, 1, 1) ~= '/'
        or type(pending.startedAt) ~= 'string' or pending.startedAt == ''
        or (pending.taskUid ~= cjson.null and not integer(pending.taskUid))
        or pending.swaps == nil or pending.createdIndex == nil then return false end
    if pending.purpose == 'guard-delete' and (pending.method ~= 'DELETE'
        or not string.match(pending.path, '^/indexes/[^/?]+$')) then return false end
    if pending.path == '/swap-indexes' then
        if pending.method ~= 'POST' or type(pending.swaps) ~= 'table' or #pending.swaps == 0 then return false end
        for _, swap in ipairs(pending.swaps) do
            if type(swap) ~= 'table' or type(swap.indexes) ~= 'table' or #swap.indexes ~= 2
                or not indexName(swap.indexes[1]) or not indexName(swap.indexes[2])
                or swap.indexes[1] == swap.indexes[2] then return false end
        end
    elseif pending.swaps ~= cjson.null then return false end
    if pending.path == '/indexes' then
        if pending.method ~= 'POST' or not indexName(pending.createdIndex) then return false end
    elseif pending.createdIndex ~= cjson.null then return false end
    return true
end

local function journalEntry(key, raw)
    local valid, entry = pcall(cjson.decode, raw)
    if not valid or type(entry) ~= 'table' or type(entry.done) ~= 'boolean'
        or type(entry.title) ~= 'string' or type(entry.completionOnly) ~= 'boolean' then
        return nil
    end
    local pending = redis.call('ZSCORE', KEYS[5], key)
    local done = redis.call('ZSCORE', KEYS[6], key)
    if entry.done then
        if pending or not done or tonumber(done) ~= tonumber(string.sub(key, 18)) then return nil end
    elseif not pending or tonumber(pending) ~= 0 or done then
        return nil
    end
    return entry
end

if operation == 'lock' then
    local acquired = redis.call('SET', KEYS[1], ARGV[2], 'NX', 'PX', ARGV[3])
    return { 'ok', acquired and 1 or 0 }
end

if operation == 'unlock' then
    if redis.call('GET', KEYS[1]) == ARGV[2] then redis.call('DEL', KEYS[1]) end
    return { 'ok', 1 }
end

local guarded = operation == 'assert' or operation == 'initialize' or operation == 'discard'
    or operation == 'writeState' or operation == 'markDone' or operation == 'prune'
    or operation == 'seal' or operation == 'retire'
if guarded then
    if redis.call('GET', KEYS[1]) ~= ARGV[2] then return { 'lost' } end
    redis.call('PEXPIRE', KEYS[1], ARGV[3])
end
if operation == 'assert' then return { 'ok', 1 } end
if operation == 'discard' then
    redis.call('DEL', unpack(KEYS, 2))
    return { 'ok', 1 }
end

local present = 0
for i = 2, #KEYS do present = present + redis.call('EXISTS', KEYS[i]) end
if operation == 'initialize' then
    if present ~= 0 then return problem('Scope already contains coordination data; refusing to overwrite it.') end
    local decoded, initial = pcall(cjson.decode, ARGV[4])
    if not decoded or not validState(initial) or initial.closing or initial.pending ~= cjson.null then
        return problem('Invalid initial coordination state.')
    end
    redis.call('HSET', KEYS[2], 'state', ARGV[4], 'sequence', '0', 'entries', '0')
    return { 'ok', 1 }
end
if present == 0 then
    if operation == 'readState' or operation == 'append' then return { 'ok', 0 } end
    if operation == 'watermark' then return { 'ok', '0' } end
    if operation == 'pages' then return { 'ok', ARGV[4], 1, {} } end
    if operation == 'entries' then return { 'ok', {} } end
    return problem('No active coordination scope exists.')
end

local types = { 'string', 'hash', 'hash', 'zset', 'zset', 'zset' }
for i, key in ipairs(KEYS) do
    local actual = redis.call('TYPE', key).ok
    if actual ~= 'none' and actual ~= types[i] then return problem('Invalid coordination key type.') end
    if i > 1 and redis.call('PTTL', key) >= 0 then return problem('Active coordination data has an expiry.') end
end

local rawState = redis.call('HGET', KEYS[2], 'state')
local sequence = redis.call('HGET', KEYS[2], 'sequence')
local entryCount = redis.call('HGET', KEYS[2], 'entries')
if not rawState or not sequence or not entryCount then
    return problem('Active coordination state is incomplete; recover lost work with indexing senders stopped.')
end
local decoded, state = pcall(cjson.decode, rawState)
if not decoded or not validState(state) then
    return problem('Invalid coordination state; refusing writes.')
end
local sequenceNumber = tonumber(sequence)
if not string.match(sequence, '^%d+$') or not sequenceNumber or sequenceNumber < 0 or sequenceNumber > maximum
    or string.format('%.0f', sequenceNumber) ~= sequence then
    return problem('Invalid journal sequence; refusing writes.')
end
local count = redis.call('ZCARD', KEYS[4])
if string.format('%.0f', count) ~= entryCount or count > sequenceNumber or redis.call('HLEN', KEYS[3]) ~= count
    or redis.call('ZCARD', KEYS[5]) + redis.call('ZCARD', KEYS[6]) ~= count then
    return problem('Journal indexes are incomplete; refusing writes.')
end

if state.closing and count ~= 0 then return problem('Closing coordination scope contains journal work.') end

if operation == 'seal' then
    if state.run ~= cjson.null or state.pending ~= cjson.null or count ~= 0 then return { 'ok', 0 } end
    state.closing = true
    redis.call('HSET', KEYS[2], 'state', cjson.encode(state))
    return { 'ok', 1 }
end
if operation == 'retire' then
    if not state.closing or state.run ~= cjson.null or state.pending ~= cjson.null or count ~= 0 then
        return problem('Only a sealed, empty coordination scope can be retired.')
    end
    redis.call('DEL', unpack(KEYS, 2))
    return { 'ok', 1 }
end
if operation == 'readState' then return { 'ok', rawState } end
if operation == 'writeState' then
    local decodedNext, nextState = pcall(cjson.decode, ARGV[4])
    if not decodedNext or not validState(nextState) or nextState.epoch ~= state.epoch
        or nextState.closing ~= state.closing then return problem('Coordination epoch or closing state changed.') end
    redis.call('HSET', KEYS[2], 'state', ARGV[4])
    return { 'ok', 1 }
end
if operation == 'watermark' then return { 'ok', sequence } end

if operation == 'append' then
    if state.closing then return { 'ok', 0 } end
    if sequenceNumber >= maximum then return problem('Journal sequence exhausted; refusing to reuse IDs.') end
    local id = redis.call('HINCRBY', KEYS[2], 'sequence', 1)
    local entry = member(tonumber(ARGV[4]), id)
    redis.call('HSET', KEYS[3], entry, ARGV[5])
    redis.call('ZADD', KEYS[4], 0, entry)
    redis.call('ZADD', KEYS[5], 0, entry)
    redis.call('HINCRBY', KEYS[2], 'entries', 1)
    return { 'ok', string.format('%.0f', id) }
end

if operation == 'pages' then
    local after = tonumber(ARGV[4])
    local through = tonumber(ARGV[5])
    local index = ARGV[6] == '1' and KEYS[5] or KEYS[4]
    local limit = tonumber(ARGV[7])
    local pages = {}
    for _ = 1, batchSize do
        local first = redis.call('ZRANGEBYLEX', index, '(' .. member(after, maximum), '+', 'LIMIT', 0, 1)
        if #first == 0 then return { 'ok', string.format('%.0f', after), 1, pages } end
        after = tonumber(string.sub(first[1], 1, 16))
        if tonumber(string.sub(first[1], 18)) <= through then
            pages[#pages + 1] = string.format('%.0f', after)
            if #pages == limit then break end
        end
    end
    return { 'ok', string.format('%.0f', after), 0, pages }
end

if operation == 'entries' then
    local page = tonumber(ARGV[4])
    local through = tonumber(ARGV[5])
    local after = tonumber(ARGV[6])
    local found = redis.call('ZRANGEBYLEX', KEYS[4], '(' .. member(page, after),
        '[' .. member(page, through), 'LIMIT', 0, batchSize)
    local entries = {}
    for _, key in ipairs(found) do
        local value = redis.call('HGET', KEYS[3], key)
        if not value then return problem('Journal entry is missing; refusing writes.') end
        if not journalEntry(key, value) then return problem('Journal entry and indexes disagree; refusing writes.') end
        entries[#entries + 1] = { string.format('%.0f', tonumber(string.sub(key, 18))), value }
    end
    return { 'ok', entries }
end

if operation == 'markDone' then
    local changes = {}
    for i = 5, #ARGV do
        local id = tonumber(ARGV[i])
        local key = member(tonumber(ARGV[4]), id)
        local raw = redis.call('HGET', KEYS[3], key)
        if raw then
            local entry = journalEntry(key, raw)
            if not entry then return problem('Journal entry and indexes disagree; refusing writes.') end
            if not entry.done then
                entry.done = true
                changes[#changes + 1] = { key, id, cjson.encode(entry) }
            end
        end
    end
    for _, change in ipairs(changes) do
        redis.call('HSET', KEYS[3], change[1], change[3])
        redis.call('ZREM', KEYS[5], change[1])
        redis.call('ZADD', KEYS[6], change[2], change[1])
    end
    return { 'ok', 1 }
end

if operation == 'prune' then
    if state.run ~= cjson.null then return problem('A rebuild still needs completed journal history.') end
    local found = redis.call('ZRANGEBYSCORE', KEYS[6], '-inf', ARGV[4], 'LIMIT', 0, batchSize)
    if #found > 0 then
        redis.call('HDEL', KEYS[3], unpack(found))
        redis.call('ZREM', KEYS[4], unpack(found))
        redis.call('ZREM', KEYS[6], unpack(found))
        redis.call('HINCRBY', KEYS[2], 'entries', -#found)
    end
    return { 'ok', #found }
end

return problem('Unknown coordination operation.')
