<?php
require_once('lib/CSSProperties.php');
require_once('lib/CSSList.php');
require_once('lib/CSSRuleSet.php');
require_once('lib/CSSRule.php');
require_once('lib/CSSValue.php');

/**
* @package html
* CSSParser class parses CSS from text into a data structure.
*/
class CSSParser { 
	private $sText;
	private $iCurrentPosition;
	private $iLength;
	
	public function __construct($sText, $sDefaultCharset = 'utf-8') {
		$this->sText = $sText;
		$this->iCurrentPosition = 0;
		$this->setCharset($sDefaultCharset);
	}
	
	public function setCharset($sCharset) {
		$this->sCharset = $sCharset;
		$this->iLength = function_exists('mb_strlen') ? mb_strlen($this->sText, $this->sCharset) : strlen($this->sText);
	}

	public function getCharset() {
			return $this->sCharset;
	}
	
	public function parse() {
		$oResult = new CSSDocument();
		$this->parseDocument($oResult);
		return $oResult;
	}
	
	private function parseDocument(CSSDocument $oDocument) {
		$this->consumeWhiteSpace();
		$this->parseList($oDocument, true);
	}
	
	private function parseList(CSSList $oList, $bIsRoot = false) {
		while(!$this->isEnd()) {
			if($this->comes('@')) {
				$oList->append($this->parseAtRule());
			} else if($this->comes('}')) {
				$this->consume('}');
				if($bIsRoot) {
					throw new Exception("Unopened {");
				} else {
					return;
				}
			} else {
				$oList->append($this->parseSelector());
			}
			$this->consumeWhiteSpace();
		}
		if(!$bIsRoot) {
			throw new Exception("Unexpected end of document");
		}
	}
	
	private function parseAtRule() {
		$this->consume('@');
		$sIdentifier = $this->parseIdentifier();
		$this->consumeWhiteSpace();
		if($sIdentifier === 'media') {
			$oResult = new CSSMediaQuery();
			$oResult->setQuery(trim($this->consumeUntil('{')));
			$this->consume('{');
			$this->consumeWhiteSpace();
			$this->parseList($oResult);
			return $oResult;
		} else if($sIdentifier === 'import') {
			$oLocation = $this->parseURLValue();
			$this->consumeWhiteSpace();
			$sMediaQuery = null;
			if(!$this->comes(';')) {
				$sMediaQuery = $this->consumeUntil(';');
			}
			$this->consume(';');
			return new CSSImport($oLocation, $sMediaQuery);
		} else if($sIdentifier === 'charset') {
			$sCharset = $this->parseStringValue();
			$this->consumeWhiteSpace();
			$this->consume(';');
			$this->setCharset($sCharset->getString());
			return new CSSCharset($sCharset);
		} else {
			//Unknown other at rule (font-face or such)
			$this->consume('{');
			$this->consumeWhiteSpace();
			$oAtRule = new CSSAtRule($sIdentifier);
			$this->parseRuleSet($oAtRule);
			return $oAtRule;
		}
	}
	
	private function parseIdentifier($bAllowFunctions = true) {
		$sResult = $this->parseCharacter(true);
		if($sResult === null) {
			throw new Exception("Identifier expected, got {$this->peek(5)}");
		}
		$sCharacter;
		while(($sCharacter = $this->parseCharacter(true)) !== null) {
			$sResult .= $sCharacter;
		}
		if($bAllowFunctions && $this->comes('(')) {
			$this->consume('(');
			$sResult = new CSSFunction($sResult, $this->parseValue());
			$this->consume(')');
		}
		return $sResult;
	}
	
	private function parseStringValue() {
		$sBegin = $this->peek();
		$sQuote = null;
		if($sBegin === "'") {
			$sQuote = "'";
		} else if($sBegin === '"') {
			$sQuote = '"';
		}
		if($sQuote !== null) {
			$this->consume($sQuote);
		}
		$sResult = "";
		$sContent = null;
		if($sQuote === null) {
			//Unquoted strings end in whitespace or with braces, brackets, parentheses
			while(!preg_match('/[\\s{}()<>\\[\\]]/isu', $this->peek())) {
				$sResult .= $this->parseCharacter(false);
			}
		} else {
			while(!$this->comes($sQuote)) {
				$sContent = $this->parseCharacter(false);
				if($sContent === null) {
					throw new Exception("Non-well-formed quoted string {$this->peek(3)}");
				}
				$sResult .= $sContent;
			}
			$this->consume($sQuote);
		}
		return new CSSString($sResult);
	}
	
	private function parseCharacter($bIsForIdentifier) {
		if($this->peek() === '\\') {
			$this->consume('\\');
			if($this->comes('\n') || $this->comes('\r')) {
				return '';
			}
			$aMatches;
			if(preg_match('/[0-9a-fA-F]/Su', $this->peek()) === 0) {
				return $this->consume(1);
			}
			$sUnicode = $this->consumeExpression('/^[0-9a-fA-F]{1,6}/u');
			if( ( function_exists('mb_strlen') && mb_strlen($sUnicode, $this->sCharset) < 6 ) || ( strlen($sUnicode) < 6 ) ) {
				//Consume whitespace after incomplete unicode escape
				if(preg_match('/\\s/isSu', $this->peek())) {
					if($this->comes('\r\n')) {
						$this->consume(2);
					} else {
						$this->consume(1);
					}
				}
			}
			$iUnicode = intval($sUnicode, 16);
			$sUtf32 = "";
			for($i=0;$i<4;$i++) {
				$sUtf32 .= chr($iUnicode & 0xff);
				$iUnicode = $iUnicode >> 8;
			}
			return iconv('utf-32le', $this->sCharset, $sUtf32);
		}
		if($bIsForIdentifier) {
			if(preg_match('/[a-zA-Z0-9]|-|_/u', $this->peek()) === 1) {
				return $this->consume(1);
			} else if(ord($this->peek()) > 0xa1) {
				return $this->consume(1);
			} else {
				return null;
			}
		} else {
			return $this->consume(1);
		}
		// Does not reach here
		return null;
	}
	
	private function parseSelector() {
		$oResult = new CSSDeclarationBlock();
		$oResult->setSelector($this->consumeUntil('{'));
		$this->consume('{');
		$this->consumeWhiteSpace();
		$this->parseRuleSet($oResult);
		return $oResult;
	}
	
	private function parseRuleSet($oRuleSet) {
		while(!$this->comes('}')) {
			$oRuleSet->addRule($this->parseRule());
			$this->consumeWhiteSpace();
		}
		$this->consume('}');
	}
	
	private function parseRule() {
		$oRule = new CSSRule($this->parseIdentifier());
		$this->consumeWhiteSpace();
		$this->consume(':');
		$this->consumeWhiteSpace();
		while(!($this->comes('}') || $this->comes(';') || $this->comes('!'))) {
			$oRule->addValue($this->parseValue());
			$this->consumeWhiteSpace();
		}
		if($this->comes('!')) {
			$this->consume('!');
			$this->consumeWhiteSpace();
			$sImportantMarker = $this->consume(strlen('important'));
			if(mb_convert_case($sImportantMarker, MB_CASE_LOWER) !== 'important') {
				throw new Exception("! was followed by “".$sImportantMarker."”. Expected “important”");
			}
			$oRule->setIsImportant(true);
		}
		if($this->comes(';')) {
			$this->consume(';');
		}
		return $oRule;
	}
	
	private function parseValue() {
		$aResult = array();
		do {
			$aResult[] = $this->parseSingleValue();
		} while($this->comes(',') && is_string($this->consume(',')));
		
		return $aResult;
	}

	private function parseSingleValue() {
		$oValue = null;
		$this->consumeWhiteSpace();
		if(is_numeric($this->peek()) || (($this->comes('-') || $this->comes('.')) && is_numeric($this->peek(1, 1)))) {
			$oValue = $this->parseNumericValue();
		} else if($this->comes('#') || $this->comes('rgb') || $this->comes('hsl')) {
			$oValue = $this->parseColorValue();
		} else if($this->comes('url')){
			$oValue = $this->parseURLValue();
		} else if($this->comes("'") || $this->comes('"')){
			$oValue = $this->parseStringValue();
		} else {
			$oValue = $this->parseIdentifier();
		}
		$this->consumeWhiteSpace();
		if($this->comes('/')) {
			$this->consume('/');
			$oValue = new CSSSlashedValue($oValue, $this->parseSingleValue());
		}
		return $oValue;
	}
	
	private function parseNumericValue() {
		$sSize = '';
		if($this->comes('-')) {
			$sSize .= $this->consume('-');
		}
		while(is_numeric($this->peek()) || $this->comes('.')) {
			if($this->comes('.')) {
				$sSize .= $this->consume('.');
			} else {
				$sSize .= $this->consume(1);
			}
		}
		$fSize = floatval($sSize);
		$sUnit = null;
		if($this->comes('%')) {
			$sUnit = $this->consume('%');
		} else if($this->comes('em')) {
			$sUnit = $this->consume('em');
		} else if($this->comes('ex')) {
			$sUnit = $this->consume('ex');
		} else if($this->comes('px')) {
			$sUnit = $this->consume('px');
		} else if($this->comes('deg')) {
			$sUnit = $this->consume('deg');
		} else if($this->comes('s')) {
			$sUnit = $this->consume('s');
		} else if($this->comes('cm')) {
			$sUnit = $this->consume('cm');
		} else if($this->comes('pt')) {
			$sUnit = $this->consume('pt');
		} else if($this->comes('in')) {
			$sUnit = $this->consume('in');
		} else if($this->comes('pc')) {
			$sUnit = $this->consume('pc');
		} else if($this->comes('cm')) {
			$sUnit = $this->consume('cm');
		} else if($this->comes('mm')) {
			$sUnit = $this->consume('mm');
		}
		return new CSSSize($fSize, $sUnit);
	}
	
	private function parseColorValue() {
		$aColor = array();
		if($this->comes('#')) {
			$this->consume('#');
			$sValue = $this->parseIdentifier(false);
			if((function_exists('mb_strlen') && mb_strlen($sValue, $this->sCharset) === 3) || (strlen($sValue) === 3)) {
				$sValue = $sValue[0].$sValue[0].$sValue[1].$sValue[1].$sValue[2].$sValue[2];
			}
			$aColor = array('r' => new CSSSize(intval($sValue[0].$sValue[1], 16)), 'g' => new CSSSize(intval($sValue[2].$sValue[3], 16)), 'b' => new CSSSize(intval($sValue[4].$sValue[5], 16)));
		} else {
			$sColorMode = $this->parseIdentifier(false);
			$this->consumeWhiteSpace();
			$this->consume('(');
			$iLength = function_exists('mb_strlen') ? mb_strlen($sColorMode, $this->sCharset) : strlen($sColorMode);
			for($i=0;$i<$iLength;$i++) {
				$this->consumeWhiteSpace();
				$aColor[$sColorMode[$i]] = $this->parseNumericValue();
				$this->consumeWhiteSpace();
				if($i < ($iLength-1)) {
					$this->consume(',');
				}
			}
			$this->consume(')');
		}
		return new CSSColor($aColor);
	}
	
	private function parseURLValue() {
		$bUseUrl = $this->comes('url');
		if($bUseUrl) {
			$this->consume('url');
			$this->consumeWhiteSpace();
			$this->consume('(');
		}
		$this->consumeWhiteSpace();
		$oResult = new CSSURL($this->parseStringValue());
		if($bUseUrl) {
			$this->consumeWhiteSpace();
			$this->consume(')');
		}
		return $oResult;
	}
	
	private function comes($sString, $iOffset = 0) {
		if($this->isEnd()) {
			return false;
		}
		return $this->peek($sString, $iOffset) == $sString;
	}
	
	private function peek($iLength = 1, $iOffset = 0) {
		if($this->isEnd()) {
			return '';
		}
		if(is_string($iLength)) {
			$iLength = function_exists('mb_strlen') ? mb_strlen($iLength, $this->sCharset) : strlen($iLength);
		}
		if(is_string($iOffset)) {
			$iOffset = function_exists('mb_strlen') ? mb_strlen($iOffset, $this->sCharset) : strlen($iOffset);
		}
		return mb_substr($this->sText, $this->iCurrentPosition+$iOffset, $iLength, $this->sCharset);
	}
	
	private function consume($mValue = 1) {
		if(is_string($mValue)) {
			$iLength = function_exists('mb_strlen') ? mb_strlen($mValue, $this->sCharset) : strlen($mValue);
			if(mb_substr($this->sText, $this->iCurrentPosition, $iLength, $this->sCharset) !== $mValue) {
				throw new Exception("Expected $mValue, got ".$this->peek(5));
			}
			$this->iCurrentPosition += function_exists('mb_strlen') ? mb_strlen($mValue, $this->sCharset) : strlen($mValue);
			return $mValue;
		} else {
			if($this->iCurrentPosition+$mValue > $this->iLength) {
				throw new Exception("Tried to consume $mValue chars, exceeded file end");
			}
			$sResult = function_exists('mb_substr') ? mb_substr($this->sText, $this->iCurrentPosition, $mValue, $this->sCharset) : substr($this->sText, $this->iCurrentPosition, $mValue);
			$this->iCurrentPosition += $mValue;
			return $sResult;
		}
	}
	
	private function consumeExpression($mExpression) {
		$aMatches;
		if(preg_match($mExpression, $this->inputLeft(), $aMatches, PREG_OFFSET_CAPTURE) === 1) {
			return $this->consume($aMatches[0][0]);
		}
		throw new Exception("Expected pattern $mExpression not found, got: {$this->peek(5)}");
	}
	
	private function consumeWhiteSpace() {
		do {
			while(preg_match('/\\s/isSu', $this->peek()) === 1) {
				$this->consume(1);
			}
		} while($this->consumeComment());
	}
	
	private function consumeComment() {
		if($this->comes('/*')) {
			$this->consumeUntil('*/');
			$this->consume('*/');
			return true;
		}
		return false;
	}
	
	private function isEnd() {
		return $this->iCurrentPosition >= $this->iLength;
	}
	
	private function consumeUntil($sEnd) {
		$iEndPos = function_exists('mb_strpos') ? mb_strpos($this->sText, $sEnd, $this->iCurrentPosition, $this->sCharset) : strpos($this->sText, $sEnd, $this->iCurrentPosition);
		if($iEndPos === false) {
			throw new Exception("Required $sEnd not found, got {$this->peek(5)}");
		}
		return $this->consume($iEndPos-$this->iCurrentPosition);
	}
	
	private function inputLeft() {
		return function_exists('mb_substr') ? mb_substr($this->sText, $this->iCurrentPosition, -1, $this->sCharset) : substr($this->sText, $this->iCurrentPosition, -1);
	}
}

