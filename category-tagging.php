<?php
/*
Plugin Name: Category Tagging
Plugin URI: http://sw-guide.de/wordpress/plugins/category-tagging/
Description: Tagging with categories. Display a tag cloud and related posts.
Version: 2.3
Author: Michael Woehrer
Author URI: http://sw-guide.de
*/

/*	----------------------------------------------------------------------------
 	    ____________________________________________________
       |                                                    |
       |                 Category Tagging                   |
       |                © Michael Woehrer                   |
       |____________________________________________________|

	© Copyright 2006-2007 Michael Woehrer (michael dot woehrer at gmail dot com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    --------------------------------------------------------------------------*/

function cattag_tagcloud(
	$min_scale = 10,
	$max_scale = 30,
	$min_include = 0,		// The minimum count to include a tag in the cloud. The default is 0 (include all tags).
	$sort_by = 'NAME_ASC',	// NAME_ASC | NAME_DESC | WEIGHT_ASC | WEIGHT_DESC
	$exclude = '',			// Tags to be excluded
	$include = '',			// Only these tags will be considered if you enter one ore more IDs
	$format = '<li><a rel="tag" href="%link%" title="%description% (%count%)" style="font-size:%size%pt">%title%<sub style="font-size:60%; color:#ccc;">%count%</sub></a></li>',
	$notfound = 'No tags found.'
	) {
	
	##############################################
	# Globals, variables, etc.
	##############################################
	$opt = array();

	$min_scale = (int) $min_scale;
	$max_scale = (int) $max_scale;
	$min_include = (int) $min_include;
	$exclude = preg_replace('/[^0-9,]/','',$exclude);	// remove everything except 0-9 and comma
	$include = preg_replace('/[^0-9,]/','',$include);	// remove everything except 0-9 and comma


	##############################################
	# Prepare order
	##############################################
	switch (strtoupper($sort_by)) {
		case 'NAME_DESC':
			$opt['$orderby'] = 'name';
			$opt['$ordertype'] = 'DESC';
	   		break;
		case 'WEIGHT_ASC':
			$opt['$orderby'] = 'count';
			$opt['$ordertype'] = 'ASC';
	   		break;
		case 'WEIGHT_DESC':
			$opt['$orderby'] = 'count';
			$opt['$ordertype'] = 'DESC';
	   		break;
		case 'RANDOM':	// Will be shuffled later 
			$opt['$orderby'] = 'name';
			$opt['$ordertype'] = 'ASC';
	   		break;
		default:	// 'NAME_ASC'
			$opt['$orderby'] = 'name';
			$opt['$ordertype'] = 'ASC';
	}

	##############################################
	# Retrieve categories
	##############################################	
	$catObjectOpt = array('type' => 'post', 'child_of' => 0, 'orderby' => $opt['$orderby'], 'order' => $opt['$ordertype'],
			'hide_empty' => true, 'include_last_update_time' => false, 'hierarchical' => 0, 'exclude' => $exclude, 'include' => $include,
			'number' => '', 'pad_counts' => false);
	$catObject = get_categories($catObjectOpt); // Returns an object of the categories
		
	##############################################
	# Prepare array
	##############################################
	// Convert object into array
	$catArray = cattag_aux_object_to_array($catObject); 

	// Remove tags
	$helper  = array_keys($catArray);	// for being able to unset 
	foreach( $helper as $cat ) { 
		if ( $catArray[$cat]['category_count'] < $min_include ) {
			unset($catArray[$cat]);
		}
	}

	// Exit if no tag found
	if (count($catArray) == 0) {
		return $notfound;
	}

	##############################################
	# Prepare font scaling
	##############################################
	// Get counts for calculating min and max values
	$countsArr = array();
	foreach( $catArray as $cat ) { $countsArr[] = $cat['category_count']; }
	$count_min = min($countsArr);
	$count_max = max($countsArr);
	
	// Calculate
	$spread_current = $count_max - $count_min; 
	$spread_default = $max_scale - $min_scale;
	if ($spread_current <= 0) { $spread_current = 1; };
	if ($spread_default <= 0) { $spread_default = 1; }
	$scale_factor = $spread_default / $spread_current;


	##############################################
	# Loop thru the values and create the result
	##############################################

	// Shuffle... -- thanks to Alex <http://www.artsy.ca/archives/159>
	if ( strtoupper($sort_by) == 'RANDOM') {
		$catArray = cattag_aux_shuffle_assoc($catArray);
	}

	$result = '';
	foreach( $catArray as $cat ) {

		// format
		$element_loop = $format;
		// font scaling		
		$final_font = (int) (($cat['category_count'] - $count_min) * $scale_factor + $min_scale);

		// replace identifiers
		$element_loop = str_replace('%link%', get_category_link($cat['cat_ID']), $element_loop);
		$element_loop = str_replace('%title%', $cat['cat_name'], $element_loop);
		$element_loop = str_replace('%description%', $cat['category_description'], $element_loop);
		$element_loop = str_replace('%count%', $cat['category_count'], $element_loop);
		$element_loop = str_replace('%size%', $final_font, $element_loop);

		// result
		$result .= $element_loop . "\n";	
	}

	$result = "\n" . '<!-- Tag Cloud, generated by \'Category Tagging Plugin\' - http://sw-guide.de/ -->' . "\n" . $result; // Please do not remove this line.
	return $result;

}

function cattag_related_posts(
	$order = 'RANDOM',
	$limit = 5,
	$exclude = '',
	$display_posts = true,
	$display_pages = false,	
	$format = '<li>%date%: <a href="%permalink%" title="%title%">%title%</a> (%commentcount%)</li>',
	$dateformat = 'd.m.y',
	$notfound = '<li>No related posts found.</li>',
	$limit_days = 365
	) {

	##############################################
	# Globals, variables, etc.
	##############################################
	global $wpdb,$post;
	
	$limit = (int) $limit;
	$exclude = preg_replace('/[^0-9,]/','',$exclude);	// remove everything except 0-9 and comma


	##############################################
	# Prepare selection of posts and pages
	##############################################
	if ( ($display_posts === true) AND ($display_pages === true) ) {
		// Display both posts and pages
		$poststatus = "IN('publish', 'static')";
	} elseif ( ($display_posts === true) AND ($display_pages === false) ) {
		// Display posts only
		$poststatus = "= 'publish'";
	} elseif ( ($display_posts === false) AND ($display_pages === true) ) {
		// Display pages only
		$poststatus = "= 'static'";
	} else {
		// Nothing can be displayed
		return $notfound;
	}

	##############################################
	# Prepare exlusion of categories
	##############################################	
	$exclude_ids_sql = ($exclude == '') ? '' : 'AND post2cat.category_id NOT IN(' . $exclude . ')';


	##############################################
	# Put the category IDs into a comma-separated string
	##############################################
	$catsList = '';
	$count = 0;
	foreach((get_the_category()) as $loop_cat) { 
		// Add category id to list
		$catsList .= ( $catsList == '' ) ? $loop_cat->cat_ID : ',' . $loop_cat->cat_ID;
	}

	##############################################
	# Prepare order
	##############################################
	switch (strtoupper($order)) {
		case 'RANDOM':
			$order_by = 'RAND()';
			break;
		default:	// 'DATE_DESC'
			$order_by = 'posts.post_date DESC';
	}


	##############################################
	# Set limit of posting date. 86400 seconds = 1 day
	##############################################
	$timelimit = '';
	if ($limit_days != 0) $timelimit = 'AND posts.post_date > ' . date('YmdHis', time() - $limit_days*86400);


	##############################################
 	# SQL query. DISTINCT is here for getting a unique result without duplicates
	##############################################
	// since we support >= WP 2.1 only, stuff like >>>AND posts.post_date < '" . current_time('mysql') . "'<<<
	// is not necessary as future posts now gain the post_status of 'future' 
	$queryresult = $wpdb->get_results("SELECT DISTINCT posts.ID, posts.post_title, posts.post_date, posts.comment_count
							FROM $wpdb->posts posts, $wpdb->post2cat post2cat
							WHERE posts.ID <> $post->ID
							AND posts.post_status $poststatus
							AND posts.ID = post2cat.post_id
							AND post2cat.category_id IN($catsList)
							$timelimit
							$exclude_ids_sql
							ORDER BY $order_by 
							LIMIT $limit
							");

	##############################################
	// Return the related posts
	##############################################
	$result = '';
	if (count($queryresult) > 0) {
		foreach($queryresult as $tag_loop) {
			// Date of post
			$loop_postdate = mysql2date($dateformat, $tag_loop->post_date);
			// Get format
			$element_loop = $format;
			// Replace identifiers
			$element_loop = str_replace('%date%', $loop_postdate, $element_loop);
			$element_loop = str_replace('%permalink%', get_permalink($tag_loop->ID), $element_loop);
			$element_loop = str_replace('%title%', $tag_loop->post_title, $element_loop);
			$element_loop = str_replace('%commentcount%', $tag_loop->comment_count, $element_loop);
			// Add to list
			$result .= $element_loop . "\n";
		}
		$result = "\n" . '<!-- Related Posts, generated by \'Category Tagging Plugin\' - http://sw-guide.de/ -->' . "\n" . $result; // Please do not remove this line.
		return $result;
	} else {
		return $notfound;
	}

}




################################################################################
# Additional functions
################################################################################
function cattag_aux_object_to_array($obj) {
	// dumps all the object properties and its associations recursively into an array
	// Source: http://de3.php.net/manual/de/function.get-object-vars.php#62470
       $_arr = is_object($obj) ? get_object_vars($obj) : $obj;
       foreach ($_arr as $key => $val) {
               $val = (is_array($val) || is_object($val)) ? cattag_aux_object_to_array($val) : $val;
               $arr[$key] = $val;
       }
       return $arr;
}


function cattag_aux_shuffle_assoc($input_array) {
	   if(!is_array($input_array) or !count($input_array))
	       return null;
	   $randomized_keys = array_rand($input_array, count($input_array));
	   $output_array = array();
	   foreach($randomized_keys as $current_key) {
	       $output_array[$current_key] = $input_array[$current_key];
	       unset($input_array[$current_key]);
	   }
	   return $output_array;
}




?>