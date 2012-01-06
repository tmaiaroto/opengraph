<?php
/*
 * Copyright 2010 Scott MacVicar
 * 
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 * 
 *		http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
*/
namespace opengraph;

use \DateTime;
use \DateTimeZone;
use \DOMDocument;
use \DOMElement;

class OpenGraph implements \Iterator {
	
	/**
	 * There are base schema's based on type, this is just
	 * a map so that the schema can be obtained
	 *
	*/
	public static $types = array(
		'activity' => array('activity', 'sport'),
		'business' => array('bar', 'company', 'cafe', 'hotel', 'restaurant'),
		'group' => array('cause', 'sports_league', 'sports_team'),
		'organization' => array('band', 'government', 'non_profit', 'school', 'university'),
		'person' => array('actor', 'athlete', 'author', 'director', 'musician', 'politician', 'public_figure'),
		'place' => array('city', 'country', 'landmark', 'state_province'),
		'product' => array('album', 'book', 'drink', 'food', 'game', 'movie', 'product', 'song', 'tv_show'),
		'website' => array('blog', 'website'),
	);
	
	/**
	 * Holds all the Open Graph values we've parsed from a page
	 *
	*/
	private $_values = array();
	
	/**
	 * Iterator position
	*/
	private $_position = 0;
	
	/**
	 * Fetches a URI and parses it for Open Graph data, returns
	 * false on error.
	 *
	 * @param string $uri URI to page to parse for Open Graph data
	 * @param array $options Various options for parsing
	 * @return object OpenGraph
	*/
	static public function fetch($uri, $options=array()) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $uri);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		// Ensure the redirects are followed (useful for short URLs)
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		$result = curl_exec($ch);
		curl_close($ch);
		
		return self::_parse($result, $options);
	}
	
	/**
	 * Parses a string for Open Graph data, returns false on error.
	 *
	 * @param string $conents A string to parse for Open Graph data
	 * @param array $options Various options for parsing
	 * @return object OpenGraph
	*/
	static public function parse($string=null, $options=array()) {
		if(empty($string)) {
			return false;
		}
		return self::_parse($string, $options);
	}

	/**
	 * Parses HTML and extracts Open Graph data, this assumes
	 * the document is at least well formed.
	 *
	 * @param string $html HTML to parse
	 * @param array $options Various options
	 * @return object OpenGraph
	*/
	static private function _parse($html=null, $options=array()) {
		// Don't even try to parse it if nothing was passed.
		if(empty($html)) {
			return false;
		}
		
		// Set default options
		$options += array(
			'date' => DATE_ISO8601,
			'timezone' => false,
			'cast' => true
		);
		
		$old_libxml_error = libxml_use_internal_errors(true);
		
		$doc = new DOMDocument();
		$doc->loadHTML($html);
		
		libxml_use_internal_errors($old_libxml_error);
		
		$tags = $doc->getElementsByTagName('meta');
		if (!$tags || $tags->length === 0) {
			return false;
		}
		
		$page = new self();
		
		foreach ($tags AS $tag) {
			if ($tag->hasAttribute('property') &&
				strpos($tag->getAttribute('property'), 'og:') === 0) {
				// All OG tags SHOULD be underscore separated according to the spec but just in case...
				$key = strtr(substr($tag->getAttribute('property'), 3), '-', '_');
				
				// OG tags can be nested. It's perverse, but it's possible.
				// Fortunately, it's only one level deep so far.
				$child = false;
				$pos = strpos($key, ':');
				if($pos !== false) {
					$parent_key = substr($key, 0, $pos);
					$child_key = substr($key, ($pos + 1));
					$child = true;
				}
				
				// Tag values can be of several types. They're all strings really, but they are
				// intended to be of certain types. Let's check them out and actually cast them.
				$content = $tag->getAttribute('content');
				
				// Casting to native PHP type is optional, but it does so by default.
				if($options['cast']) {
					// Booleans
					// OG documentation says literal value can be 1 or 0 for this too? So then
					// what happens to 1 the integer? Everything off a web page is a string... Huh?
					if($content === 'true') {
						$content = true;
					}
					if($content === 'false') {
						$content = false;
					}
					
					// Integers and Floats
					if(is_numeric($content)) {
						if(stristr($content, '.') || stristr($content, 'E')) {
							$content = (float)$content;
						} else {
							$int = (int)$content;
							// This ensures that numbers too large for PHP to handle as an integer 
							// get converted to float instead.
							if($int == PHP_INT_MAX) {
								$content = (float)$content;
							} else {
								$content = $int;
							}
						}
					}
					
					// ISO 8601 Dates
					// (actually, this is going to format a lot of various date formats)
					$stamp = strtotime($content); 
					if (is_numeric($stamp) && checkdate(date('m', $stamp), date('d', $stamp), date('Y', $stamp)) ) {
						$date = new DateTime($content);
						// Optionally, force a timezone, otherwise the system's timezone will be used
						// when there is not a timezone specified in the OG tag.
						if($options['timezone']) {
							$date->setTimeZone(new DateTimeZone($options['timezone']));
						}
						// If the date format is set to object or datetime then the DateTime object
						// will be returned and the user can format the date however they like.
						if($options['date'] == 'object' || $options['date'] == 'datetime') {
							$content = $date;
						} else {
							// DATE_ISO8601 by default (this will force any valid date to be ISO8601 strict.
							$content = $date->format($options['date']);
						}
					}
				}
				
				// Do the actual parsing
				if(!$child) {
					// Tags can be single or an array
					if(isset($page->_values[$key]) && !empty($page->_values[$key]) && $child === false) {
						if(!is_array($page->_values[$key])) {
							$single_value = $page->_values[$key];
							$page->_values[$key] = array(
								array('value' => $single_value),
								array('value' => $content),
							);
						} else {
							$page->_values[$key][] = array('value' => $content);
						}
					} else {
						$page->_values[$key] = $content;
					}
				} else {
					if(!is_array($page->_values[$parent_key])) {
						$single_value = $page->_values[$parent_key];
						$page->_values[$parent_key] = array(
							array(
								'value' => $single_value,
								$child_key => $content
							)
						);
					} else {
						$i = count($page->_values[$parent_key]) - 1;
						if(is_array($page->_values[$parent_key][$i])) {
							$page->_values[$parent_key][$i][$child_key] = $content;
						} else {
							$single_value_in_array = $page->_values[$parent_key][$i];
							$page->_values[$parent_key][$i] = array(
								'value' => $single_value_in_array,
								$child_key => $content
							);
						}
					}
				}
				
			}
		}
		
		if (empty($page->_values)) {
			return false;
		}
		
		return $page;
	}
	
	/**
	 * Helper method to access attributes directly
	 * Example:
	 * $graph->title
	 *
	 * @param $key    Key to fetch from the lookup
	*/
	public function __get($key) {
		if (array_key_exists($key, $this->_values)) {
			return $this->_values[$key];
		}
		
		/*
		 * What was the following even supposed to do?
		 * What if OG changed and there was a schema tag somehow magically? 
		 * This would then be an issue. If some sort of overall schema is to be returned,
		 * here is not the place.
		if ($key === 'schema') {
			foreach (self::$types AS $schema => $types) {
				if (array_search($this->_values['type'], $types)) {
					return $schema;
				}
			}
		}
		*/
	}
	
	/**
	 * Return all the keys found on the page.
	 *
	 * @return array
	*/
	public function keys() {
		return array_keys($this->_values);
	}
	
	/**
	 * Return all the tags found on the page.
	 * This method is an alternative to the getter method.
	 * 
	 * @return array
	*/
	public function values() {
		return $this->_values;
	}

	/**
	 * Helper method to check an attribute exists
	 *
	 * @param $key
	*/
	public function __isset($key) {
		return array_key_exists($key, $this->_values);
	}

	/**
	 * Will return true if the page has location data embedded
	 *
	 * @return boolean Check if the page has location data
	*/
	public function hasLocation() {
		if (array_key_exists('latitude', $this->_values) && array_key_exists('longitude', $this->_values)) {
			return true;
		}
		
		$address_keys = array('street_address', 'locality', 'region', 'postal_code', 'country_name');
		$valid_address = true;
		foreach ($address_keys AS $key) {
			$valid_address = ($valid_address && array_key_exists($key, $this->_values));
		}
		return $valid_address;
	}

	/*
	 * Implements from Iterator rewind() method
	*/
	public function rewind() {
		reset($this->_values); $this->_position = 0;
	}
	
	/*
	 * Implements from Iterator current() method
	*/
	public function current() {
		return current($this->_values);
	}
	
	/*
	 * Implements from Iterator key() method
	*/
	public function key() {
		return key($this->_values);
	}
	
	/*
	 * Implements from Iterator next() method
	*/
	public function next() {
		next($this->_values); ++$this->_position;
	}
	
	/*
	 * Implements from Iterator valid() method
	*/
	public function valid() {
		return $this->_position < sizeof($this->_values);
	}
}
?>