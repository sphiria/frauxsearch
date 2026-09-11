<?php

namespace FrauxSearch\Tests;

use FrauxSearch\BoostConfigParser;
use PHPUnit\Framework\TestCase;

class BoostConfigParserTest extends TestCase {
	public function testRoundingIsIndependentOfTemplateQueryOrder(): void {
		$rules = [ 'A' => 1, 'B' => 150, 'C' => 50 ];
		$this->assertSame( 1, BoostConfigParser::calculateBoost( $rules, [ 'A', 'B', 'C' ] ) );
		$this->assertSame( 1, BoostConfigParser::calculateBoost( $rules, [ 'C', 'A', 'B' ] ) );
		$this->assertSame( 1, BoostConfigParser::calculateBoost( $rules, [ 'B', 'C', 'A', 'A' ] ) );
		$this->assertSame( 100, BoostConfigParser::calculateBoost( $rules, [ 'Unknown' ] ) );
	}

	public function testParsesRulesAndComments(): void {
		$this->assertSame( [
			'Character' => 150,
			'BackOneLevel' => 50,
		], BoostConfigParser::parse( "# comment\nTemplate:Character|150% # boost\nBackOneLevel|50%\n" ) );
	}

	public function testRejectsMalformedRules(): void {
		$this->assertSame( [], BoostConfigParser::parse(
			"Template:Decimal|12.5%\nTemplate:MissingPercent|12\nTemplate:MissingValue|%\n"
		) );
	}

	public function testNormalizesSpacesAndLastRuleWins(): void {
		$this->assertSame( [ 'Character_(Rising)' => 140 ], BoostConfigParser::parse(
			"Template:Character (Rising)|100%\nTemplate:Character (Rising)|140%"
		) );
	}

	public function testReportsMalformedLineNumbers(): void {
		$this->assertSame(
			[ 'Line 2: invalid boost rule' ],
			BoostConfigParser::parseWithWarnings( "Template:Good|150%\nTemplate:Bad|1.5%" )['warnings']
		);
	}

	public function testFindsAddedRemovedAndChangedTemplates(): void {
		$this->assertEqualsCanonicalizing(
			[ 'Removed', 'Changed', 'Added' ],
			BoostConfigParser::changedTemplates(
				[ 'Same' => 100, 'Removed' => 50, 'Changed' => 100 ],
				[ 'Same' => 100, 'Changed' => 150, 'Added' => 200 ]
			)
		);
	}
}
