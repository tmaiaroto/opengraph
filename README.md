# Open Graph Protocol helper for PHP

A small library for making accessing of Open Graph Protocol data easier

## Note
Keys with a dash (-) in the name are converted to _ for easy access as a property
in PHP, though there should not be any dashed keys in Open Graph.

## Required Extensions
* DOM for parsing

See more here: http://www.php.net/manual/en/book.dom.php
(It's part of the libxml extension for PHP, it's fairly common)

## Usage
	use opengraph\OpenGraph;

	$graph = OpenGraph::fetch('http://www.rottentomatoes.com/m/10011268-oceans/');

	// Or, if you already have the HTML content from a page
	$graph = OpenGraph::parse($content);

	foreach($graph as $tag => $value) {
		echo $tag . ' => ' . $value;
	}

	var_dump($graph->title);

	// Show all the keys or Open Graph tags, that have been defined on the page
	var_dump($graph->keys());
	