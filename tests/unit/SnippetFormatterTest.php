<?php

namespace FrauxSearch\Tests;

use FrauxSearch\SnippetFormatter;
use PHPUnit\Framework\TestCase;

class SnippetFormatterTest extends TestCase {
	public function testEscapesMarkupAndPreservesOnlyRequestHighlights(): void {
		$this->assertSame(
			'&lt;img src=x onerror=alert(1)&gt; <span class="searchmatch">猫 &amp; dog</span>',
			SnippetFormatter::format(
				'<img src=x onerror=alert(1)> __open__猫 & dog__close__', [ '__open__', '__close__' ]
			)
		);
	}

	public function testEscapesFallbackTitlesAndLiteralHighlightHtml(): void {
		$this->assertSame(
			'&lt;span class=&quot;searchmatch&quot;&gt;A &amp; B&lt;/span&gt;',
			SnippetFormatter::format( '<span class="searchmatch">A & B</span>' )
		);
	}

	public function testInvalidUtf8DoesNotDiscardSnippet(): void {
		$this->assertSame( "a\u{FFFD}b", SnippetFormatter::format( "a\xffb" ) );
	}
}
