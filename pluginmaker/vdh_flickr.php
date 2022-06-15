<?php

// This is a PLUGIN TEMPLATE.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Uncomment and edit this line to override:
# $plugin['name'] = 'abc_plugin';

$plugin['version'] = '0.8.14';
$plugin['author'] = 'Ralph von der Heyden';
$plugin['author_uri'] = 'http://www.rvdh.net/vdh_flickr';
$plugin['description'] = 'Shows your flickr.com pictures in TextPattern.';

// Plugin types:
// 0 = regular plugin; loaded on the public web side only
// 1 = admin plugin; loaded on both the public and admin side
// 2 = library; loaded only when include_plugin() or require_plugin() is called
$plugin['type'] = 0; 


@include_once('zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---
h1. vdh_flickr documentation

Please see the "vdh_flickr wiki on Google Code":http://code.google.com/p/vdhflickr/wiki/Documentation for detailled documentation.
# --- END PLUGIN HELP ---
<?php
}

# --- BEGIN PLUGIN CODE ---
/* vdh_flickr Textpattern plugin
*
* Author: Ralph von der Heyden <flickr@rvdh.net>
*         http://www.rvdh.net/vdh_flickr
*
*
* License: GPL
*
*/

/* Here you can translate or adjust things as you like.
*
*/
global $text;
$text = array();
$text['error_message'] = 'Failed to connect to flickr.com!';
$text['latest'] = 'My latest Pictures...';

global $clean_urls;
//$clean_urls = 1;

global $nsid;
// $nsid = '12345678@N00';


class Vdh_Flickr {
	var $api_key, $email, $nsid, $password, $userdata, $xml, $form, $use_articleurl;

	function Vdh_Flickr($params) {
		$this->api_key = 'c34e40dc707f9bc52736dba56811893f';
		if(isset($params['error_message'])) {
			$GLOBALS['text']['error_message'] = $params['error_message'];
		}
		if(isset($params['clean_urls'])) {
			$GLOBALS['clean_urls'] = $params['clean_urls'];
		}
		$mainversion = strtok (PHP_VERSION,".");
		if(!($mainversion >= 5)) {
			$GLOBALS['use_php4'] = 1;
		}
		if (isset($params['email'])) $this->email = $params['email'];
		(isset($GLOBALS['nsid']))?
		$this->nsid = $GLOBALS['nsid']:
		$this->nsid = $params['nsid'];
		if ($GLOBALS['is_article_list'] === false) $this->use_articleurl = true;
		if (isset($params['use_articleurl']) and $params['use_articleurl'] == 1) $this->use_articleurl = true;
		if (isset($params['use_articleurl']) and $params['use_articleurl'] == 0) $this->use_articleurl = false;
		if (!isset($this->nsid)) $this->getNsid();
		$this->userdata = '&api_key=' . $this->api_key . '&user_id=' . $this->nsid;
		if (isset($params['password'])) {
			$this->password = $params['password'];
			$this->userdata .= '&password=' . $this->password;
		}
		if (isset($this->email)) $this->userdata .= '&email=' . $this->email;
	}

	function getNsid() {
		$method = 'flickr.people.findByEmail&api_key=' . $this->api_key . '&find_email=' . $this->email;
		$this->xml = new Flickr($method);
		if($this->xml->isValid()) {
			$this->nsid = array_shift($this->xml->xpath('/rsp/user/@id'));
		}
	}

	function makeRequest($parameters) {
		$keys = array_keys($parameters);
		if ($GLOBALS['permlink_mode'] == 'messy') {
			($this->use_articleurl)?
			$html = permlinkurl($GLOBALS['thisarticle']):
			$html = hu.'?s='.urlencode($GLOBALS['s']);
			for($i = 0; $i < sizeof($parameters); $i++) {
				$html .= '&amp;' . $keys[$i] . '=' . $parameters[$keys[$i]];
			}
			return $html;
		}
		else if ($GLOBALS['clean_urls']) {
			($this->use_articleurl)?
			$html = permlinkurl($GLOBALS['thisarticle']):
			$html = hu.urlencode($GLOBALS['s']);
			for($i = 0; $i < sizeof($parameters); $i++) {
				$html .= '/' . $keys[$i] . '/' . $parameters[$keys[$i]];
			}
			return $html;
		}
		else {
			($this->use_articleurl)?
			$html = permlinkurl($GLOBALS['thisarticle']) . '/?' . $keys[0] . '=' .$parameters[$keys[0]]:
			$html = hu.urlencode($GLOBALS['s']) . '/?' . $keys[0] . '=' .$parameters[$keys[0]];
			for($i = 1; $i < sizeof($parameters); $i++) {
				$html .= '&amp;' . $keys[$i] . '=' . $parameters[$keys[$i]];
			}
		}
		return $html;
	}

	function getPhotoUrl($photo, $size) {
		$farm = $photo['farm'];
		$server = $photo['server'];
		$id = (isset($photo['primary']))? $photo['primary'] : $photo['id'];
		$secret = $photo['secret'];
		$format = "jpg";
		$imgsize = ("n" != $size)? "_" . $size : "";
		if ("o" == $size)
		{
			$secret = $photo['original_secret'];
			$format = $photo['original_format'];
		}
		$img_url = "http://farm${farm}.static.flickr.com/${server}/${id}_${secret}${imgsize}.${format}";
		return $img_url;
	}

	function __toString() {
		return $this->nsid;
	}
}


class Gallery extends Vdh_Flickr {
	var $xml, $i, $set, $set_preview_size, $mode;
	var $sets_per_page, $page, $previous_page, $next_page, $lastpage, $number_of_sets;
	var $exceptions = array();
	var $sets = array();

	function Gallery($params) {
		$this->Vdh_Flickr($params);
		if (isset($params['except'])) $this->exceptions = explode(",", $params['except']);
		(isset($params['set_preview_size']))?
		$this->set_preview_size = @$params['set_preview_size']:
		$this->set_preview_size = 'm';
		(isset($_GET['page']))?
		$this->page = $_GET['page']:
		$this->page = 1;
		(!empty($params['mode']))?
		$this->mode = $params['mode']:
		$this->mode = 'all';
		$this->getSets($params);
		if (isset($params['sets_per_page'])) {
			$this->sets_per_page = $params['thumbs_per_page'];
			$this->lastpage = ceil($this->number_of_sets / $this->sets_per_page);
			if ($this->page-1 >= 1) {
				$this->previous_page = $this->page - 1;
			}
			else {
				$this->previous_page = $this->lastpage;
			}
			if ($this->page+1 <= $this->lastpage) {
				$this->next_page = $this->page + 1;
			}
			else {
				$this->next_page =  1;
			}
		}
		if (isset($params['set_form'])) {
			$this->form = fetch('Form','txp_form',"name",$params['set_form']);
		}
		else {
			$this->form = '
			<div class="setpreview">
			<div class="thumbnail">
			<txp:vdh_flickr_set_link title="Proceed to this gallery"><txp:vdh_flickr_set_img title="Proceed to this gallery" /></txp:vdh_flickr_set_link>
			</div>
			<div>
			<h3 class="title"><txp:vdh_flickr_set_link title="Proceed to this gallery"><txp:vdh_flickr_set_title /></txp:vdh_flickr_set_link></h3>
			<h4 class="number_of_photos"><txp:vdh_flickr_set_number_of_photos /> Photos</h4>
			<p class="set_description"><txp:vdh_flickr_set_description /></p>
			</div>
			<div style="clear:both;"></div>
			</div>';
		}
	}

	function getSets($params) {
		$method = 'flickr.photosets.getList' . $this->userdata;
		$this->xml = new Flickr($method);
		if($this->xml->isValid()) {
			$i=1;
			foreach($this->xml->xpath('/rsp/photosets/photoset') as $photoset) {
				$set_id = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/@id"));
				if($this->mode=='all') {
					if(!in_array($set_id, $this->exceptions)) {
						$this->add_to_sets($set_id, $i);
					}
				}
				if($this->mode=='none') {
					if(in_array($set_id, $this->exceptions)) {
						$this->add_to_sets($set_id, $i);
					}
				}
				$i++;
			}
		}
	}

	function add_to_sets($set_id, $i) {
		$set = array('id' => $set_id);
		$set['title'] = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/title/text()"));
		$set['description'] = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/description/text()"));
		$set['primary'] = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/@primary"));
		$set['farm'] = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/@farm"));
		$set['secret'] = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/@secret"));
		$set['server'] = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/@server"));
		$set['photos'] = array_shift($this->xml->xpath("/rsp/photosets/photoset[$i]/@photos"));
		array_push($this->sets, $set);
	}

	function set_img($params) {
		$img_url = $this->getPhotoUrl($this->set, $this->set_preview_size);
		$img_url = '<img src="' . $img_url . '" alt="' . $this->titles[$this->i];
		(isset($params['title']))? $img_url .= '" title="' . $params['title'] . '" />' : $img_url .= '" />';
		return $img_url;
	}

	function set_link($params, $thing) {
		$what = ' href="'. $this->makeRequest(array('set' => $this->set['id'])) .'"';
		if (isset($params['title'])) $what .= ' title="' . $params['title'] . '"';
		$html = tag($thing,'a',$what);
		return $html;
	}

	function set_title() {
		$html = $this->set['title'];
		return $html;
	}

	function set_number_of_photos() {
		$html = $this->set['photos'];
		return $html;
	}

	function set_description() {
		$html = $this->set['description'];
		return $html;
	}

	function set_list($params) {
		if(!$this->xml->isValid()) {
			return false;
		}
		(isset($params['wraptag']) == true)? $wraptag = $params['wraptag'] : $wraptag = '';
		(isset($params['break']) == true)? $break = $params['break'] : $break = 'br';
		$this->i = 0;
		foreach ($this->sets as $this->set) {
			($_GET['set'] == (float) $this->set['id'])? $param = ' class="current"' : '';
			($break != 'br')?
			$html .= tag($this->set_link('', $this->set_title()), $break, $param) . "\n":
			$html .= $this->set_link('', $this->set_title()) .'<br />' . "\n";
			$this->i++;
			unset($param);
		}
		return (($wraptag != '') == true)? tag($html, $wraptag) : $html;
	}

	function __toString() {
		if(!$this->xml->isValid()) {
			return $GLOBALS['text']['error_message'];
		}
		$this->i = 0;
		$result = '';
		foreach ($this->sets as $this->set) {
			$result .= parse($this->form);
			$this->i++;
		}
		return $result;
	}
}


class Thumbnails extends Vdh_Flickr {
	var $xml, $id, $owner, $primary, $secret, $number_of_photos, $title, $description, $set, $photo, $latest;
	var $thumbnail_size, $img_size, $open, $tag_and, $link, $use_art_id_as_tag;
	var $page, $thumbs_per_page, $start, $end, $lastpage, $previous_page, $next_page;
	var $photos = array(), $tags = array();

	function Thumbnails($params) {
		$this->Vdh_Flickr($params);
		$this->params = $params;
		(isset($params['thumbnail_size']))?
		$this->thumbnail_size = $params['thumbnail_size']:
		$this->thumbnail_size = 's';
		(isset($params['img_size']))?
		$this->img_size = $params['img_size']:
		$this->img_size = 'n';
		(isset($params['open']))?
		$this->open = $params['open']:
		$this->open = 'self';
		(isset($params['set']))?
		$this->set = $params['set']:
		$this->set = $_GET['set'];
		(isset($_GET['tags']))?
		@$this->tags = $_GET['tags']:
		@$this->tags = $params['tags'];
		if(!empty($this->tags)) unset($this->set);
		(isset($_GET['page']))?
		$this->page = $_GET['page']:
		$this->page = 1;
		if (isset($params['latest'])) $this->latest = $params['latest'];
		(isset($params['linkthumbs']))?
		$this->linkthumbs = $params['linkthumbs']:
		$this->linkthumbs = 1;
		(isset($params['use_art_id_as_tag']))?
		$this->use_art_id_as_tag = $params['use_art_id_as_tag']:
		$this->use_art_id_as_tag = 0;
		if (isset($params['tag_and'])) $this->tag_and = 1;
		if (isset($params['thumbnails_form'])) $this->form = fetch('Form','txp_form',"name",$params['thumbnails_form']);
		else {
			$this->form = '
			<h3><txp:vdh_flickr_thumbnails_title />, <txp:vdh_flickr_thumbnails_number_of_photos /> Photos</h3>
			<p class="flickr_slideshow">
			<txp:vdh_flickr_thumbnails_slideshow>&raquo; Show as slideshow in new window.</txp:vdh_flickr_thumbnails_slideshow>
			</p>
			<txp:vdh_flickr_thumbnails_if_description>
			<p class="flickr_thumbnails_description">
			<txp:vdh_flickr_thumbnails_description />
			</p>
			</txp:vdh_flickr_thumbnails_if_description>
			<div class="flickrset">
			<txp:vdh_flickr_thumbnails_list />
			</div>
			<txp:vdh_flickr_thumbnails_if_multiple_pages>
			<h3 class="pages_nav">pages navigation</h3>
			<p>
			thumbs per page: <txp:vdh_flickr_thumbnails_per_page /><br />
			Showing page <txp:vdh_flickr_thumbnails_current_page /> of <txp:vdh_flickr_thumbnails_total_pages />.<br />
			Showing thumb <txp:vdh_flickr_thumbnails_pages_startthumb /> to <txp:vdh_flickr_thumbnails_pages_endthumb />.<br />
			<txp:vdh_flickr_thumbnails_pages_first>&laquo; first</txp:vdh_flickr_thumbnails_pages_first> |
			<txp:vdh_flickr_thumbnails_pages_previous>&lt; previous</txp:vdh_flickr_thumbnails_pages_previous> |
			<txp:vdh_flickr_thumbnails_pages_next>next &gt;</txp:vdh_flickr_thumbnails_pages_next> |
			<txp:vdh_flickr_thumbnails_pages_last>last &raquo;</txp:vdh_flickr_thumbnails_pages_last>
			</p>
			Go to page number:
			<txp:vdh_flickr_thumbnails_pages_list wraptag="ul" break="li" class ="thumbs_pages" />
			</txp:vdh_flickr_thumbnails_if_multiple_pages>
			<div style="clear:both;"></div>';
		}
		$this->getPhotos();
		$this->thumbs_per_page = 0;
		if (isset($params['thumbs_per_page'])) {
			$this->thumbs_per_page = $params['thumbs_per_page'];
			$this->lastpage = ceil($this->number_of_photos / $this->thumbs_per_page);
			if ($this->page-1 >= 1) {
				$this->previous_page = $this->page - 1;
			}
			else {
				$this->previous_page = $this->lastpage;
			}
			if ($this->page+1 <= $this->lastpage) {
				$this->next_page = $this->page + 1;
			}
			else {
				$this->next_page =  1;
			}
		}
	}

	function getPhotos() {
		if ($this->use_art_id_as_tag == 1) {
			global $thisarticle;
			if(isset($this->tags) and ($this->tags != '')) {
				$this->tags .= ',';
			}
			$this->tags .= 'article'.$thisarticle['thisid'];
		}
		if (isset($this->tags)) {
			$this->title = $this->tags;
			$method = 'flickr.photos.search' . $this->userdata . '&tags=' . $this->tags . '&per_page=500&extras=original_format';
			if (isset($this->tag_and)) {
				$method .= '&tag_mode=all';
			}
			$this->xml = new Flickr($method);
			if($this->xml->isValid()) {
				foreach($this->xml->xpath('/rsp/photos/photo/@id') as $photo_id) {
					$photo = array('id' => $photo_id);
					$photo['title'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@title"));
					$photo['secret'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@secret"));
					$photo['original_secret'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@originalsecret"));
					$photo['original_format'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@originalformat"));
					$photo['server'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@server"));
					$photo['farm'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@farm"));
					array_push($this->photos, $photo);
				}
				$this->number_of_photos = (string) array_shift($this->xml->xpath('/rsp/photos/@total'));
				if (isset($this->params['random'])) $this->randomize($this->params['random']);
			}
		}
		if (isset($this->latest)) {
			$this->title = $GLOBALS['text']['latest'];
			$method = 'flickr.photos.search' . $this->userdata;
			if ($this->thumbs_per_page == 0 or $this->latest <= $this->thumbs_per_page) $method .= '&per_page=' . $this->latest;
			else {
				$method .= '&per_page=' . $this->thumbs_per_page;
			}
			$method .= "&extras=original_format";
			$this->xml = new Flickr($method);
			if($this->xml->isValid()) {
				foreach($this->xml->xpath('/rsp/photos/photo/@id') as $photo_id) {
					$photo = array('id' => $photo_id);
					$photo['title'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@title"));
					$photo['secret'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@secret"));
					$photo['original_secret'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@originalsecret"));
					$photo['original_format'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@originalformat"));
					$photo['server'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@server"));
					$photo['farm'] = array_shift($this->xml->xpath("/rsp/photos/photo[@id=".$photo_id."]/@farm"));
					array_push($this->photos, $photo);
				}
				$this->number_of_photos = (string) $this->latest;
				if (isset($this->params['random'])) $this->randomize($this->params['random']);
			}
		}
		if (isset($this->set)) {
			$method = 'flickr.photosets.getPhotos' . $this->userdata . '&photoset_id=' . $this->set . "&extras=original_format";
			$this->xml = new Flickr($method);
			if($this->xml->isValid()) {
				foreach($this->xml->xpath('/rsp/photoset/photo/@id') as $photo_id) {
					$photo = array('id' => $photo_id);
					$photo['title'] = array_shift($this->xml->xpath("/rsp/photoset/photo[@id=".$photo_id."]/@title"));
					$photo['secret'] = array_shift($this->xml->xpath("/rsp/photoset/photo[@id=".$photo_id."]/@secret"));
					$photo['original_secret'] = array_shift($this->xml->xpath("/rsp/photoset/photo[@id=".$photo_id."]/@originalsecret"));
					$photo['original_format'] = array_shift($this->xml->xpath("/rsp/photoset/photo[@id=".$photo_id."]/@originalformat"));
					$photo['server'] = array_shift($this->xml->xpath("/rsp/photoset/photo[@id=".$photo_id."]/@server"));
					$photo['farm'] = array_shift($this->xml->xpath("/rsp/photoset/photo[@id=".$photo_id."]/@farm"));
					array_push($this->photos, $photo);
				}
				$this->number_of_photos = (string) sizeof($this->photos);
				if (isset($this->params['random'])) $this->randomize($this->params['random']);
			}
		}
	}

	function randomize($randsize) {
		if ($randsize < $this->number_of_photos) {
			shuffle($this->photos);
			$this->photos = array_slice($this->photos, 0, $randsize);
			$this->number_of_photos = $randsize;
		}
	}

	function getInfo() {
		$method = 'flickr.photosets.getInfo' . $this->userdata . '&photoset_id=' . $this->set;
		$this->xml = new Flickr($method);
		if($this->xml->isValid()) {
			$this->id = array_shift($this->xml->xpath('/rsp/photoset/@id'));
			$this->primary = array_shift($this->xml->xpath('/rsp/photoset/@primary'));
			$this->secret = array_shift($this->xml->xpath('/rsp/photoset/@secret'));
			$this->title = array_shift($this->xml->xpath('/rsp/photoset/title/text()'));
			$this->description = array_shift($this->xml->xpath('/rsp/photoset/description/text()'));
			if (empty($this->description)) $this->description = '&nbsp;';
		}
	}

	function thumbnails_title() {
		if (isset($this->title) == false) $this->getInfo();
		return $this->title;
	}

	function thumbnails_description() {
		if ((isset($this->description) == false) and isset($this->set)) {
			$this->getInfo();
		}
		if ($this->description != '&nbsp;' and !empty($this->description)) return $this->description;
		return '';
	}

	function thumbnails_if_description ($thing) {
		if ((isset($this->description) == false) and isset($this->set)) {
			$this->getInfo();
		}
		if ($this->description != '&nbsp;' and !empty($this->description)) $result = parse($thing);
		return $result;
	}

	function thumbnails_slideshow($thing) {
		if(isset($this->tags)) {
			$html = '<a href="http://www.flickr.com/slideShow/index.gne?nsid='.urlencode($this->nsid).'&amp;user_id='.urlencode($this->nsid);
			if(isset($this->tag_and)) {
				$html .= '&amp;tag_mode=all&amp;tags='. urlencode($this->tags);
			}
			else {
				$html .= '&amp;tag_mode=any&amp;tags=' . urlencode($this->tags);
			}
			$html .= '"  onclick="window.open(this.href, \'slideShowWin\', \'width=500,height=500,top=150,left=70,scrollbars=no, status=no, resizable=no\'); ';
			$html .= 'return false;">';
		}
		if(isset($this->set)) {
			$html = '<a href="http://www.flickr.com/slideShow/index.gne?nsid='.urlencode($this->nsid).'&amp;set_id='.$this->set;
			$html .= '"  onclick="window.open(this.href, \'slideShowWin\', \'width=500,height=500,top=150,left=70,scrollbars=no, status=no, resizable=no\'); ';
			$html .= 'return false;">';
		}
		if(isset($this->latest)) {
			$html = '<a href="http://www.flickr.com/slideShow/index.gne?nsid='.urlencode($this->nsid).'&amp;user_id='.urlencode($this->nsid).'&amp;maxThumbs='.$this->latest;
			$html .= '"  onclick="window.open(this.href, \'slideShowWin\', \'width=500,height=500,top=150,left=70,scrollbars=no, status=no, resizable=no\'); ';
			$html .= 'return false;">';
		}
		$html .= $thing.'</a>';
		return $html;
	}

	function thumbnails_img() {
		$img_url = $this->getPhotoUrl($this->photo, $this->thumbnail_size);
		$img_url = '<img src="' . $img_url . '" alt="' . $this->photo['title'] . '" />';
		return $img_url;
	}

	function thumbnails_img_title() {
		return $this->photo['title'];
	}

	function thumbnails_link($img_url, $title ="") {
		if($this->open == 'self') {
			if (isset($this->set)) {
				$parameters['set'] = $this->set;
			}
			$parameters['img'] = $this->photo['id'];
			$html = tag($img_url,'a',' href="'. $this->makeRequest($parameters) .'"')."\n";
		}
		if($this->open == 'flickr') {
			$html = '<a href="http://www.flickr.com/photos/' . urlencode($this->nsid) . '/' . $this->photo['id'] . '/">' . "\n";
			$html .= $img_url;
			$html .= '</a>' . "\n";
		}
		if($this->open == 'window') {
			$html = '<a href="';
			$html .= $this->getPhotoUrl($this->photo, $this->img_size);
			$html .= '" onclick="window.open(this.href, \'popupwindow\', \'resizable\'); return false;">';
			$html .= $img_url;
			$html .= '</a>';
		}
		if($this->open == 'lightbox') {
			$html = '<a href="';
			$html .= $this->getPhotoUrl($this->photo, $this->img_size);
			$set = $this->thumbnails_title();
			$set = trim($set);
			$html .= '" rel="lightbox['.$set.']"';
			$html .= ' title="'.$title.'">';
			$html .= $img_url;
			$html .= '</a>';
		}
		return $html;
	}

	function thumbnails_list($params) {
		if(!$this->xml->isValid()) {
			return false;
		}
		(isset($params['listmode']))?
		$this->listmode = $params['listmode']:
		$this->listmode = 'img';
		(isset($params['wraptag']) == true)? $wraptag = $params['wraptag'] : $wraptag = '';
		(isset($params['break']) == true)? $break = $params['break'] : $break = '';
		if ($this->thumbs_per_page == 0) {
			$this->start = 1;
			$this->end = sizeof($this->photos);
		}
		else {
			$this->start = (float) ($this->thumbs_per_page * ($this->page - 1)) + 1;
			$this->end = min($this->thumbs_per_page * $this->page, sizeof($this->photos));
		}
		$html = '';
		for ($this->i = $this->start - 1; $this->i <= $this->end - 1; $this->i++) {
			$this->photo = $this->photos[$this->i];
			(@$_GET['img'] == (float) $this->photo['id'])? $param = ' class="current"' : '';
			($this->listmode == 'img')? $what = $this->thumbnails_img() : $what = $this->thumbnails_img_title();
			($this->linkthumbs == 1)? $what = $this->thumbnails_link($what, $this->photo["title"]) : '';
			if($break != '') {
				($break != 'br')?
				$html .= tag($what, $break, $param) . "\n":
				$html .= $what .'<br />' . "\n";
			}
			else {
				$html .= $what . "\n";
			}
			unset($param);
		}
		return (($wraptag != '') == true)? tag($html, $wraptag) : $html;
	}

	function thumbnails_number_of_photos () {
		return $this->number_of_photos;
	}

	function thumbnails_per_page () {
		return $this->thumbs_per_page;
	}

	function thumbnails_current_page () {
		return $this->page;
	}

	function thumbnails_total_pages () {
		return $this->lastpage;
	}

	function thumbnails_pages_first ($thing) {
		if (isset($this->set)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('set' => $this->id, 'page' => 1)) .'"');
		if (isset($this->tags)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('tags' => $this->tags, 'page' => 1)) .'"');
		if (isset($this->latest)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('page' => 1)) .'"');
		return $result;
	}

	function thumbnails_pages_last ($thing) {
		if (isset($this->set)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('set' => $this->id, 'page' => $this->lastpage)) .'"');
		if (isset($this->tags)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('tags' => $this->tags, 'page' => $this->lastpage)) .'"');
		if (isset($this->latest)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('page' => $this->lastpage)) .'"');
		return $result;
	}

	function thumbnails_pages_previous ($thing) {
		if (isset($this->set)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('set' => $this->id, 'page' => $this->previous_page)) .'"');
		if (isset($this->tags)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('tags' => $this->tags, 'page' => $this->previous_page)) .'"');
		if (isset($this->latest)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('page' => $this->previous_page)) .'"');
		return $result;
	}

	function thumbnails_pages_next ($thing) {
		if (isset($this->set)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('set' => $this->id, 'page' => $this->next_page)) .'"');
		if (isset($this->tags)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('tags' => $this->tags, 'page' => $this->next_page)) .'"');
		if (isset($this->latest)) $result = tag(parse($thing), 'a', ' href="'. $this->makeRequest(array('page' => $this->next_page)) .'"');
		return $result;
	}

	function thumbnails_pages_startthumb () {
		return $this->start;
	}

	function thumbnails_pages_endthumb () {
		return $this->end;
	}

	function thumbnails_pages_list ($params) {
		$pages_array = range(1, $this->lastpage);
		for ($i = 0; $i <= $this->lastpage - 1; $i++) {
			if (isset($this->set)) $pages_array[$i] = tag($pages_array[$i], 'a', ' href="'. $this->makeRequest(array('set' => $this->id, 'page' => $pages_array[$i])) .'"');
			if (isset($this->tags)) $pages_array[$i] = tag($pages_array[$i], 'a', ' href="'. $this->makeRequest(array('tags' => $this->tags, 'page' => $pages_array[$i])) .'"');
			if (isset($this->latest)) $pages_array[$i] = tag($pages_array[$i], 'a', ' href="'. $this->makeRequest(array('page' => $pages_array[$i])) .'"');
		}
		return doWrap($pages_array, @$params['wraptag'], @$params['break'], @$params['class'], @$params['breakclass'], @$params['atts']);
	}

	function thumbnails_if_multiple_pages ($thing) {
		($this->thumbs_per_page != 0)? $result = parse($thing) : '';
		return $result;
	}

	function __toString() {
		if(!$this->xml->isValid()) {
			return $GLOBALS['text']['error_message'];
		}
		$result = parse($this->form);
		return $result;
	}
}


class Picture extends Vdh_Flickr {
	var $xml, $id, $secret, $server, $date_uploaded, $title, $description, $notes, $set, $link, $img, $context;
	var $img_size, $show_img_nav, $show_img_title, $method, $set_title, $comments, $context_mode;
	var $date_posted, $date_taken;
	var $previous = array(), $next = array(), $tags = array(), $raw_tags = array(), $tag_ids = array();

	function Picture($params) {
		$this->Vdh_Flickr($params);
		(isset($params['img_size']))?
		$this->img_size = $params['img_size']:
		$this->img_size = 'n';
		(isset($params['original_size']))?
		$this->original_size = $params['original_size']:
		$this->original_size = 'o';
		(isset($params['set']))?
		$this->set = $params['set']:
		$this->set = $_GET['set'];
		(isset($params['img']))?
		$this->img = $params['img']:
		$this->img = $_GET['img'];
		$method = 'flickr.photos.getInfo' . $this->userdata . '&photo_id=' . $this->img;
		$this->xml = new Flickr($method);
		if($this->xml->isValid()) {
			$this->id = array_shift($this->xml->xpath('/rsp/photo/@id'));
			$this->secret = array_shift($this->xml->xpath('/rsp/photo/@secret'));
			$this->original_secret = array_shift($this->xml->xpath('/rsp/photo/@originalsecret'));
			$this->original_format = array_shift($this->xml->xpath('/rsp/photo/@originalformat'));
			$this->farm = array_shift($this->xml->xpath('/rsp/photo/@farm'));
			$this->server = array_shift($this->xml->xpath('/rsp/photo/@server'));
			$this->date_posted = array_shift($this->xml->xpath('/rsp/photo/dates/@posted'));
			$this->date_taken = array_shift($this->xml->xpath('/rsp/photo/dates/@taken'));
			$this->title = array_shift($this->xml->xpath('/rsp/photo/title/text()'));
			$this->description = array_shift($this->xml->xpath('/rsp/photo/description/text()'));
			$this->comments = array_shift($this->xml->xpath('/rsp/photo/comments/text()'));
			$this->raw_tags = $this->xml->xpath('/rsp/photo/tags/tag/@raw');
			$this->tags = $this->xml->xpath('/rsp/photo/tags/tag/text()');
			$this->link = @$params['link'];
		}
		if (isset($params['img_form'])) {
			$this->form = fetch('Form','txp_form',"name",$params['img_form']);
		}
		else {
			$this->form = '
			<div class="individual"><div class="image">
			<h2 class="title"><txp:vdh_flickr_img_title /></h2>
			<txp:vdh_flickr_img_link><txp:vdh_flickr_img_naked /></txp:vdh_flickr_img_link>
			<div class="flickrsetnav">
			<txp:vdh_flickr_img_previous label="previous&nbsp;:&nbsp;">&larr;</txp:vdh_flickr_img_previous>
			<h2 class="setname"><txp:vdh_flickr_img_set_link><txp:vdh_flickr_img_set_title /></txp:vdh_flickr_img_set_link></h2>
			<txp:vdh_flickr_img_next label="next&nbsp;:&nbsp;">&rarr;</txp:vdh_flickr_img_next>
			</div>
			<div class="flickr_tag_list">
			<txp:vdh_flickr_img_tags separator=" | " />
			</div>
			<div class="flickr_comments">
			<txp:vdh_flickr_img_number_of_comments /> Comments.
			<txp:vdh_flickr_img_comments_invite>Show and post comments!</txp:vdh_flickr_img_comments_invite><br />
			Posted <txp:vdh_flickr_img_date_posted />.<br />
			Taken <txp:vdh_flickr_img_date_taken />.<br />
			</div>
			<txp:vdh_flickr_img_if_description>
			<p class="img_description">
			<txp:vdh_flickr_img_description />
			</p>
			</txp:vdh_flickr_img_if_description>
			</div></div><div style="clear:both;"></div>';
		}
	}

	function getContext() {
		if (isset($this->set)) {
			$method = 'flickr.photosets.getContext' . $this->userdata . '&photo_id=' . $this->img . '&photoset_id=' . $this->set;
		}
		else {
			$method = 'flickr.photos.getContext' . $this->userdata . '&photo_id=' . $this->img;
		}
		$this->xml = new Flickr($method);
		$this->previous['id'] = array_shift($this->xml->xpath('/rsp/prevphoto/@id'));
		$this->previous['title'] = array_shift($this->xml->xpath('/rsp/prevphoto/@title'));
		$this->previous['thumb'] = array_shift($this->xml->xpath('/rsp/prevphoto/@thumb'));
		$this->next['id'] = array_shift($this->xml->xpath('/rsp/nextphoto/@id'));
		$this->next['title'] = array_shift($this->xml->xpath('/rsp/nextphoto/@title'));
		$this->next['thumb'] = array_shift($this->xml->xpath('/rsp/nextphoto/@thumb'));
		$this->context = true;
	}

	function img_naked() {
		$photo = array("farm" => $this->farm, "server" => $this->server, "id" => $this->id, "secret" => $this->secret, "original_secret" => $this->original_secret, "original_format" => $this->original_format);
		$img_url = $this->getPhotoUrl($photo, $this->img_size);
		$img_url = '<img src="' . $img_url . '" alt="' . $this->title . '" />';
		return $img_url;
	}

	function img_link($img_url) {
		if (isset($this->link)){
			switch($this->link) {
				case "flickr":
				$link_target = 'http://www.flickr.com/photos/' . urlencode($this->nsid) . '/' . $this->id . '/';
				break;
				case "original_size" || "lightbox":
				$photo = array("farm" => $this->farm, "server" => $this->server, "id" => $this->id, "secret" => $this->secret, "original_secret" => $this->original_secret, "original_format" => $this->original_format);
				$link_target = $this->getPhotoUrl($photo, $this->original_size);
				break;
				case "img_information":
				$link_target = 'http://www.flickr.com/photo_exif.gne?id=' . $this->id;
				break;
			}
			$link_attributes = ' href="' . $link_target . '"';
			if ($this->link == "lightbox") {
				$link_attributes .= ' rel="lightbox"';
			}
			$img_url = tag($img_url, 'a', $link_attributes);
			$img_url .= "\n";
		}
		return $img_url;
	}

	function img_previous($label, $thing) {
		if (!isset($this->context)) $this->getContext();
		$this->previous['id'] = (float) ($this->previous['id']);
		if ($this->previous['id'] != 0) {
			$title =  $this->previous['title'];
			$end = '" title="'. $label . $title;
			if (isset($this->set)) $html = tag($thing,'a',' href="'. $this->makeRequest(array('set' => $this->set, 'img' => $this->previous['id'])) .$end.'"')."\n";
			else $html = tag($thing,'a',' href="'. $this->makeRequest(array('img' => $this->previous['id'])) .$end.'"')."\n";
		}
		return $html;
	}

	function img_previous_thumbnail() {
		if (!isset($this->context)) $this->getContext();
		$this->previous['id'] = (float) ($this->previous['id']);
		if ($this->previous['id'] != 0) {
			$result = '<img src="' . $this->previous['thumb'] . '" alt="' . $title . '" />';
		}
		return $result;
	}

	function img_next($label, $thing) {
		if (!isset($this->context)) $this->getContext();
		$this->next['id'] = (float) ($this->next['id']);
		if ($this->next['id'] != 0) {
			$title =  $this->next['title'];
			$end = '" title="'. $label . $title;
			if (isset($this->set)) $html = tag($thing,'a',' href="'. $this->makeRequest(array('set' => $this->set, 'img' => $this->next['id'])) .$end.'"')."\n";
			else $html = tag($thing,'a',' href="'. $this->makeRequest(array('img' => $this->next['id'])) .$end.'"')."\n";
		}
		return $html;
	}

	function img_next_thumbnail() {
		if (!isset($this->context)) $this->getContext();
		$this->next['id'] = (float) ($this->next['id']);
		if ($this->next['id'] != 0) {
			$result = '<img src="' . $this->next['thumb'] . '" alt="' . $title . '" />';
		}
		return $result;
	}

	function img_set_title() {
		if (isset($this->set)) {
			$method = 'flickr.photosets.getInfo' . $this->userdata . '&photoset_id=' . $this->set;
			$xml = new Flickr($method);
			$this->set_title = array_shift($xml->xpath('/rsp/photoset/title/text()'));
			return $this->set_title;
		}
		return '';
	}

	function img_set_link($thing) {
		if (isset($this->set)) {
			return tag($thing, 'a', ' href="'. $this->makeRequest(array('set' => $this->set)) .'"');
		}
		return '';
	}

	function img_nav() {
		$html .= $this->img_previous();
		$html .= '<h2 class="setname">' . $this->img_set_title() . '</h2>' . "\n";
		$html .= $this->img_next();
		return $html;
	}

	function img_title() {
		return $this->title;
	}

	function img_description() {
		$description_string = (string) $this->description;
		if ($description_string != 'Array') return $description_string;
	}

	function img_if_description ($thing) {
		if ($this->description != '&nbsp;' and !empty($this->description)) $result = parse($thing);
		else $result = '';
		return $result;
	}

	function img_tags($separator) {
		$html = tag($this->raw_tags[0],'a',' href="' . $this->makeRequest(array('tags' => $this->tags[0])) . '"')."\n";
		for($i = 1; $i < sizeof($this->tags); $i++) {
			$html .= $separator;
			$html .= tag($this->raw_tags[$i],'a',' href="' . $this->makeRequest(array('tags' => $this->tags[$i])) . '"')."\n";
		}
		return $html;
	}

	function img_number_of_comments() {
		return $this->comments;
	}

	function img_comments_invite($invitetext) {
		return tag($invitetext,'a',' href="' . 'http://www.flickr.com/photos/' . urlencode($this->nsid) . '/' . $this->id . '/"');
	}

	function img_date_posted($params) {
		if (isset($params['format'])) return safe_strftime($params['format'], $this->date_posted);
		global $archive_dateformat;
		return safe_strftime($archive_dateformat, $this->date_posted);
	}

	function img_date_taken($params) {
		if (isset($params['format'])) return safe_strftime($params['format'], strtotime($this->date_taken));
		global $archive_dateformat;
		return safe_strftime($archive_dateformat, strtotime($this->date_taken));
	}

	function __toString() {
		if(!$this->xml->isValid()) {
			return $GLOBALS['text']['error_message'];
		}
		$result = parse($this->form);
		return $result;
	}
}


class Taglist extends Vdh_Flickr {
	var $xml, $taglist, $count, $source_tag;

	function Taglist($params) {
		$this->Vdh_Flickr($params);
	}

	function list_all($params) {
		$method = 'flickr.tags.getListUser' . $this->userdata;
		return $this->generateList($method, $params);
	}

	function list_popular($params) {
		isset($params['count'])? $this->count = $params['count'] : $this->count = 10;
		$method = 'flickr.tags.getListUserPopular' . $this->userdata . '&count=' . $this->count;
		return $this->generateList($method, $params);
	}

	function list_related($params) {
		isset($params['source_tag'])? $this->source_tag = $params['source_tag'] : $this->source_tag = '';
		$method = 'flickr.tags.getRelated' . $this->userdata . '&tag=' . $this->source_tag;
		return $this->generateList($method, $params);
	}

	function generateList ($method, $params) {
		$this->xml = new Flickr($method);
		if($this->xml->isValid()) {
			$this->taglist = $this->xml->xpath('//tags/tag/text()');
		}
		for ($i = 0; $i <= sizeof($this->taglist) - 1; $i++) {
			$this->taglist[$i] = tag($this->taglist[$i],'a',' href="' . $this->makeRequest(array('tags' => $this->taglist[$i])) . '"');
		}
		return doWrap($this->taglist, $params['wraptag'], $params['break'], $params['class'], $params['breakclass'], $params['atts']);
	}

	function __toString() {
		return $this->list_all;
	}
}


class Flickr {
	var $xmlurl = 'http://www.flickr.com/services/rest/?method=';
	var $xml;

	function Flickr($method) {
		//$time_start = microtime(true);
		$this->xmlurl .= $method;
		if(isset($GLOBALS['use_php4'])) {
			if (function_exists('curl_init')) {
				$ch = @curl_init();
				curl_setopt($ch, CURLOPT_URL, $this->xmlurl);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				$resp = curl_exec($ch);
				curl_close($ch);
				if($dom = @domxml_open_mem($resp)) {
					$this->xml = xpath_new_context($dom);
				}
			}
			else if ($resp = @file_get_contents($this->xmlurl)) {
				if($dom = @domxml_open_mem($resp)) {
					$this->xml = xpath_new_context($dom);
				}
			}
			else if (function_exists('domxml_open_file')){
				$dom = @domxml_open_file($this->xmlurl);
				$this->xml = xpath_new_context($dom);
			}
		}
		else {
			if(!$this->xml = @simplexml_load_file($this->xmlurl)) {
				unset($this->xml);
			}
		}
		//echo n,comment(('runtime for ' . $method. ': ' . (microtime(true) - $time_start) . "<br />\n"));
	}

	function isValid() {
		if(isset($this->xml)) {
			$res = array_shift($this->xpath('/rsp/@stat'));
			if ($res == 'fail') {
				return false;
			}
			return true;
		}
		else {
			return false;
		}
	}

	function xpath($path) {
		if(!isset($this->xml)) {
			return NULL;
		}
		if(isset($GLOBALS['use_php4'])) {
			$result = xpath_eval_expression($this->xml, $path);
			$result = $result->nodeset;
			// Convert to String:
			for($iterator = 0; $iterator < count($result); $iterator++) {
				$result[$iterator] = $result[$iterator]->get_content();
			}
			return $result;
		}
		else {
			$result = $this->xml->xpath($path);
			// Convert to String:
			for($iterator = 0; $iterator < count($result); $iterator++) {
				$result[$iterator] = (string) $result[$iterator];
			}
			return $result;
		}
	}
}

function vdh_flickr($params) {
	if(isset($_GET['img'])) {
		return vdh_flickr_img($params);
	}
	if(isset($_GET['tags'])) {
		return vdh_flickr_thumbnails($params);
	}
	if(isset($_GET['set'])) {
		return vdh_flickr_thumbnails($params);
	}
	global $gal;
	//((isset ($gal))==false)? $gal = new Gallery($params) : '';
	if (!empty($params)) $gal = new Gallery($params);
	return $gal->__toString();
}

function vdh_flickr_thumbnails($params) {
	if(isset($_GET['img'])) {
		return vdh_flickr_img($params);
	}
	global $thumbs;
	//((isset ($thumbs))==false)? $thumbs = new Thumbnails($params) : '';
	if (!empty($params)) $thumbs = new Thumbnails($params);
	return $thumbs->__toString();
}

function vdh_flickr_img($params) {
	global $singleimg;
	//((isset ($singleimg))==false)? $singleimg = new Picture($params) : '';
	if (!empty($params)) $singleimg = new Picture($params);
	return $singleimg->__toString();
}

//neue txp tags

global $vdh_flickr;

function vdh_flickr_set_img ($params) {
	global $gal;
	return $gal->set_img($params);
}

function vdh_flickr_set_title ($params) {
	global $gal;
	return $gal->set_title($params);
}

function vdh_flickr_set_description () {
	global $gal;
	return $gal->set_description();
}

function vdh_flickr_set_number_of_photos () {
	global $gal;
	return $gal->set_number_of_photos();
}

function vdh_flickr_set_link ($params, $thing) {
	$result = parse($thing);
	global $gal;
	return $gal->set_link($params, $result);
}

function vdh_flickr_set_list ($params) {
	global $gal;
	((isset ($gal))==false)? $gal = new Gallery($params) : '';
	return $gal->set_list($params);
}

function vdh_flickr_tag_list_all ($params) {
	$taglalala = new Taglist($params);
	return $taglalala->list_all($params);
}

function vdh_flickr_tag_list_popular ($params) {
	$taglalala = new Taglist($params);
	return $taglalala->list_popular($params);
}

function vdh_flickr_tag_list_related ($params) {
	$taglalala = new Taglist($params);
	return $taglalala->list_related($params);
}

function vdh_flickr_thumbnails_title () {
	global $thumbs;
	return $thumbs->thumbnails_title();
}

function vdh_flickr_thumbnails_description () {
	global $thumbs;
	return $thumbs->thumbnails_description();
}

function vdh_flickr_thumbnails_if_description ($params, $thing) {
	global $thumbs;
	return $thumbs->thumbnails_if_description($thing);
}

function vdh_flickr_thumbnails_slideshow ($params, $thing) {
	$result = parse($thing);
	global $thumbs;
	return $thumbs->thumbnails_slideshow($result);
}

function vdh_flickr_thumbnails_img () {
	global $thumbs;
	return $thumbs->thumbnails_img();
}

function vdh_flickr_thumbnails_img_title () {
	global $thumbs;
	return $thumbs->thumbnails_img_title();
}

function vdh_flickr_thumbnails_link ($params, $thing) {
	$result = parse($thing);
	global $thumbs;
	return $thumbs->thumbnails_link($result);
}

function vdh_flickr_thumbnails_list ($params) {
	global $thumbs;
	if (isset($_GET['set']) or isset($_GET['tags']) or isset($params['set']) or isset($params['tags']) or isset($params['latest'])) {
		if ((isset ($thumbs))==false) $thumbs = new Thumbnails($params);
	}
	if (isset($_GET['set']) or isset($_GET['tags']) or isset($thumbs->set) or isset($thumbs->tags) or isset($thumbs->latest)) {
		return $thumbs->thumbnails_list($params);
	}
	return false;
}

function vdh_flickr_thumbnails_number_of_photos() {
	global $thumbs;
	return $thumbs->thumbnails_number_of_photos();
}

function vdh_flickr_thumbnails_per_page() {
	global $thumbs;
	return $thumbs->thumbnails_per_page();
}

function vdh_flickr_thumbnails_current_page() {
	global $thumbs;
	return $thumbs->thumbnails_current_page();
}

function vdh_flickr_thumbnails_total_pages() {
	global $thumbs;
	return $thumbs->thumbnails_total_pages();
}

function vdh_flickr_thumbnails_pages_first($params, $thing) {
	global $thumbs;
	return $thumbs->thumbnails_pages_first($thing);
}

function vdh_flickr_thumbnails_pages_last($params, $thing) {
	global $thumbs;
	return $thumbs->thumbnails_pages_last($thing);
}

function vdh_flickr_thumbnails_pages_previous($params, $thing) {
	global $thumbs;
	return $thumbs->thumbnails_pages_previous($thing);
}

function vdh_flickr_thumbnails_pages_next($params, $thing) {
	global $thumbs;
	return $thumbs->thumbnails_pages_next($thing);
}

function vdh_flickr_thumbnails_pages_list($params) {
	global $thumbs;
	return $thumbs->thumbnails_pages_list($params);
}

function vdh_flickr_thumbnails_pages_startthumb() {
	global $thumbs;
	return $thumbs->thumbnails_pages_startthumb();
}

function vdh_flickr_thumbnails_pages_endthumb() {
	global $thumbs;
	return $thumbs->thumbnails_pages_endthumb();
}

function vdh_flickr_thumbnails_if_multiple_pages($params, $thing) {
	global $thumbs;
	return $thumbs->thumbnails_if_multiple_pages($thing);
}

function vdh_flickr_img_title () {
	global $singleimg;
	return $singleimg->img_title();
}

function vdh_flickr_img_description () {
	global $singleimg;
	return $singleimg->img_description();
}

function vdh_flickr_img_if_description ($params, $thing) {
	global $singleimg;
	return $singleimg->img_if_description($thing);
}

function vdh_flickr_img_naked () {
	global $singleimg;
	return $singleimg->img_naked();
}

function vdh_flickr_img_link ($params, $thing) {
	$result = parse($thing);
	global $singleimg;
	return $singleimg->img_link($result);
}

function vdh_flickr_img_previous ($params, $thing) {
	$result = parse($thing);
	global $singleimg;
	return $singleimg->img_previous($params['label'], $result);
}

function vdh_flickr_img_previous_thumbnail () {
	global $singleimg;
	return $singleimg->img_previous_thumbnail();
}

function vdh_flickr_img_next ($params, $thing) {
	$result = parse($thing);
	global $singleimg;
	return $singleimg->img_next($params['label'], $result);
}

function vdh_flickr_img_next_thumbnail () {
	global $singleimg;
	return $singleimg->img_next_thumbnail();
}

function vdh_flickr_img_set_title () {
	global $singleimg;
	return $singleimg->img_set_title();
}

function vdh_flickr_img_set_link ($params, $thing) {
	$result = parse($thing);
	global $singleimg;
	return $singleimg->img_set_link($result);
}

function vdh_flickr_img_tags ($params) {
	global $singleimg;
	return $singleimg->img_tags($params['separator']);
}

function vdh_flickr_img_number_of_comments () {
	global $singleimg;
	return $singleimg->img_number_of_comments();
}

function vdh_flickr_img_comments_invite ($params, $thing) {
	$result = parse($thing);
	global $singleimg;
	return $singleimg->img_comments_invite($result);
}

function vdh_flickr_img_date_posted ($params) {
	global $singleimg;
	return $singleimg->img_date_posted($params);
}

function vdh_flickr_img_date_taken ($params) {
	global $singleimg;
	return $singleimg->img_date_taken($params);
}

function vdh_flickr_env($params) {
	global $vdh_flickr;
	if(isset($_GET['img'])) {
		global $singleimg;
		$vdh_flickr['is_img'] = 1;
		$singleimg = new Picture($params);
		return '';
	}
	if(isset($_GET['tags'])) {
		global $thumbs;
		$vdh_flickr['is_thumbnails'] = 1;
		$vdh_flickr['is_tags'] = 1;
		$thumbs = new Thumbnails($params);
		return '';
	}
	if(isset($_GET['set'])) {
		global $thumbs;
		$vdh_flickr['is_thumbnails'] = 1;
		$vdh_flickr['is_set'] = 1;
		$thumbs = new Thumbnails($params);
		return '';
	}
	global $gal;
	((isset ($gal))==false)? $gal = new Gallery($params) : '';
	$vdh_flickr['is_preview'] = 1;
	return '';
}

function vdh_flickr_if_preview($params, $thing) {
	global $vdh_flickr;
	return (@$vdh_flickr['is_preview'] == true) ? parse($thing) : '';
}

function vdh_flickr_if_thumbnails($params, $thing) {
	global $vdh_flickr;
	return (@$vdh_flickr['is_thumbnails'] == true) ? parse($thing) : '';
}

function vdh_flickr_if_set($params, $thing) {
	global $vdh_flickr;
	return (@$vdh_flickr['is_set'] == true) ? parse($thing) : '';
}

function vdh_flickr_if_tags($params, $thing) {
	global $vdh_flickr;
	return (@$vdh_flickr['is_tags'] == true) ? parse($thing) : '';
}

function vdh_flickr_if_img($params, $thing) {
	global $vdh_flickr;
	return (@$vdh_flickr['is_img'] == true) ? parse($thing) : '';
}

function vdh_flickr_show_nsid($params) {
	$nsidcheck = new Vdh_flickr($params);
	return $nsidcheck->__toString();
}
# --- END PLUGIN CODE ---

?>
