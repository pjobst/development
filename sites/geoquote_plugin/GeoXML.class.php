<?php

/*************************************************

GeoXML - XML Call to GeoQuote / Telarus
Author: "Aaron Jay" Lieberman <alieberman@telarus.com>
Copyright (c): 2006, Telarus, Inc.
Version: 1.0

03.21.07 - DEV - Added getXMLNodeValue, xml2Array, and xml2Object functions.

* This library is distributed with a purchased copy of GeoQuote Remote.
* This software may not be duplicated, distributed, resold or repackaged
* without the written consent of Telarus, Inc. Draper, Utah.
 
*************************************************/

class GeoXML {
	
	var $XMLError = 0; // Set to 0 if no XML parsing error, otherwise return 1
	var $vals, $myxml, $p;
	var $i = 0;
	var $MessageContent, $MessageSubject;
	var $formvars;
	var $Results;
	
	function fetchXML($xmlURL, $XMLToSend = "", $XMLNotifyList = "xmlalert@shopfort1.com" ) {

		$this->XMLError = 1;
		
		include_once("Snoopy.class.php"); 		// Include the snoopy class
		$snoopy = new Snoopy; 
		
		for ($i=0;$i < count($xmlURL);$i++) {   // Loop through each of the backup pages
			if ($this->XMLError == 1) { // This is either the first attempt or the previous attempt failed
				
				if ($XMLToSend == "") { // Just grab the page
					$snoopy->fetch($xmlURL[$i]);
				} else {
					$formvars["xml"] = $XMLToSend;
					$snoopy->submit($xmlURL[$i], $formvars);
				}
				
				$this->XMLError = 0;
				$Results = trim($snoopy->results);   // $Results now holds the content of the page

				// Trim off the header information from the message content to avoid xml parsing issues
				if (stristr($Results,'<?xml ')) {
					$Results = stristr($Results,'<?xml ');
				}	
					
								
				$this->Results = $Results;	
				
				$trans = array("&gt;" => "&#62;", "&lt;" => "&#60;", "&amp;" => "&#38;", "&quot;" => "&#34;", "&apos;" => "&#44;");
				$Results = strtr($Results, $trans);
				
				if (($Results == "Connection Failure") || (strpos($Results, "Error Occurred While Processing Request") != false)) {
					$this->XMLError = 1;
				}
				
			
				if ($this->XMLError == 0) {
					$p = xml_parser_create();
					$this->XMLError = 1 - xml_parse_into_struct($p, $Results, $this->vals, $this->myxml);
					xml_parser_free($p);
				}
				
				if ($this->XMLError == 1) { // There was a problem parsing the XML.
					$headers = "MIME-Version: 1.0\r\n"
					. "Content-type: text/html; charset=iso-8859-1\r\n"
					. "From:xmlalert@shopfort1.com\r\n";
					$recipients = $XMLNotifyList;
					$MessageSubject = "Error with " . $_SERVER['SERVER_NAME'] . " " . $_SERVER['SCRIPT_NAME'];
					$MessageContent = "Parse error with " . $_SERVER['SERVER_NAME'] . " " . $_SERVER['SCRIPT_NAME'] . "<br><br>\n\n"
					. $xmlURL[$i] . "<br><br>\n\n"
					. $XMLToSend . "<br>\n\n"
					. print_r($Results, 1);
					
					mail($recipients, $MessageSubject, $MessageContent, $headers);
				}
			}
		}
	}
	
	function getXMLNodeValue($nodeName, $indexVal, $default) {
		if (!array_key_exists($nodeName, $this->myxml)) return $default;
		else if (!array_key_exists($indexVal, $this->myxml[$nodeName])) return $default;
		else if (!array_key_exists('value', $this->vals[$this->myxml[$nodeName][$indexVal]])) return $default;
		else return $this->vals[$this->myxml[$nodeName][$indexVal]]['value'];
	}
	
	function xml2Object() {
		$elements = array();
		$stack = array();
		
		foreach ($this->vals as $tag) {
			$index = count($elements);
			if ($tag['type'] == 'complete' || $tag['type'] == 'open') {
				$elements[$index] = new GeoXmlElement;
				$elements[$index]->name = $tag['tag'];
				$elements[$index]->attributes = &$tag['attributes'];
				$elements[$index]->content = &$tag['value'];
				if ($tag['type'] == 'open') {
					$elements[$index]->children = array();
					$stack[count($stack)] = &$elements;
					$elements = &$elements[$index]->children;
				}
			}
			if ($tag['type'] == 'close') {
				$elements = &$stack[count($stack) - 1];
				unset($stack[count($stack) - 1]);
			}
		}
		
		return $elements[0];  // the single top-level element
	}
	
	
	function xml2Array() {
		$a = $this->_subdivide($this->vals);
		$a = $this->_correctEntries($a);
		return $a;
	}
	
	/**********************************************************************************************
	 * Private function called from xml2Array() and recursively from itself.
	 **********************************************************************************************/
	function _subdivide($a, $level = 1) {
		foreach($a as $k => $v) {
			if ($v['level'] === $level && $v['type'] === 'open') {
				$toplvltag = $v['tag'];
			} elseif($v['level'] === $level && $v['type'] === 'close' && $v['tag'] === $toplvltag) {
				$newarray[$toplvltag][] = $this->_subdivide($temparray, ($level + 1));
				unset($temparray, $nextlvl);
			} elseif($v['level'] === $level && $v['type'] === 'complete') {
				$newarray[$v['tag']] = $v['value'];
			} elseif($v['type'] === 'complete' || $v['type'] === 'close' || $v['type'] === 'open') {
				$temparray[] = $v;
			}
		}
		return $newarray;
	}
	
	/**********************************************************************************************
	 * Private function called from xml2Array() and recursively from itself.
	 **********************************************************************************************/
	function _correctEntries($a) {
		if(is_array($a)) {
			$keys = array_keys($a);
			if(count($keys) == 1 && is_int($keys[0])) {
				$tmp = $a[0];
				unset($a[0]);
				$a = $tmp;
			}
			$keys2 = array_keys($a);
			foreach($keys2 as $key) {
				$tmp2 = $a[$key];
				unset($a[$key]);
				$a[$key] = $this->_correctEntries($tmp2);
				unset($tmp2);
			}
		}
		return $a;
	}
	
	/**********************************************************************************************
	 * Used to ensure that you can loop over a group of values returned by xml2Array() even when
	 * there is only one element in the group.  This is necessary because xml2Array() handles multiple
	 * instances of a tag with the same name by creating a numerically indexed array containing 
	 * each instance of the tag as an element.  However, if there is only one instance of that tag
	 * then an associate array is returned and you will end up looping over any elements in the
	 * tag instead of multiple instances of the tag.
	 **********************************************************************************************/
	function ensureNumericArray($a) {
		if(is_numeric(key($a))) $newArray = $a;
		else {
			$newArray = array();
			$newArray[] = $a;
		}
		return $newArray;
	}
	
}


class GeoXmlElement {
  var $name;
  var $attributes;
  var $content;
  var $children;
}