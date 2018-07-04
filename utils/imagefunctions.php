<?php

class baseAlbumImage {
    /*
    Can be initialised in one of several ways
    
    1. With baseimage (eg albumart/small/thignrirvu.jpg) in order to calculate the paths for the other sizes
        and, optionally, artist, album etc so an image key can be generated
    2. With a key, in which case the artist info etc will be looked up in the collection
    3. With Artist and Album info (see image_info_from_album_info) in order to do an image search or calculate an image key
        For 'artist' of PODCAST, albumpath must be set to the podcasts' base directory (the PODindex).
        A key must NOT be supplied in this case.
    */
    
    public function __construct($params) {
        global $prefs;
        foreach (array('artist', 'album', 'key', 'source', 'file', 'base64data', 'mbid', 'albumpath', 'albumuri') as $param) {
            if (array_key_exists($param, $params) && $params[$param] != '') {
                $this->{$param} = $params[$param];
            } else {
                $this->{$param} = null;
            }
        }
        // We need to be able to send baseimage as '' for it all to work with the collection,
        // And X-AlbumImage defaults to null for all kinds of reasons, probably.
        if (array_key_exists('baseimage', $params)) {
            $this->baseimage = $params['baseimage'];
        } else {
            $this->baseimage = 'No-Image';
        }
        if (array_key_exists("ufile", $_FILES)) {
            $this->file = $_FILES['ufile']['name'];
        }
        if (preg_match('/\d+/', $this->mbid) && !preg_match('/-/', $this->mbid)) {
            debuglog(" Supplied MBID of ".$mbid." looks more like a Discogs ID", "ALBUMIMAGE");
            $this->mbid = null;
        }
        if ($prefs['player_backend'] == 'mopidy') {
            $this->albumpath = urldecode($this->albumpath);
        }
        $this->image_downloaded = false;
        if ($this->baseimage != 'No-Image') {
            $this->images = $this->image_paths_from_base_image($params['baseimage']);
            $this->key = $this->make_image_key();
        } else if ($this->key !== null) {
            $this->image_info_from_database();
        } else {
            $this->images = $this->image_info_from_album_info();
        }
    }
    
    public function get_image_key() {
        return $this->key;
    }
    
    private function image_exists($image) {
        debuglog("Checking for existence of file ".$image,"ALBUMIMAGE");
        return file_exists($image);
    }
    
    public function get_image_if_exists() {
        if ($this->image_exists($this->images['small'])) {
            return $this->images['small'];
        } else {
            return null;
        }
    }
    
    public function get_images() {
        return $this->images;
    }
    
    private function check_if_image_already_downloaded() {
        $checkimages = $this->image_info_from_album_info();
        if ($this->image_exists($checkimages['small'])) {
            $this->images = $checkimages;
            return true;
        } else {
            return false;
        }
    }

    public function check_image($domain, $type, $in_playlist = false) {
        global $doing_search;
        // If there's no image, see if we can set a default
        // Note we don't set defaults for streams because coverscaper handles those
        // so it can set them in the playlist even when auto art download is off
        
        $disc_checked = false;
        debuglog("Checking Image","ALBUMIMAGE");
        if ($this->images['small'] == '' || $this->images['small'] === null) {
            
            debuglog("  There Is No Image","ALBUMIMAGE");
            
            if ($this->artist == 'STREAM') {
                // Stream images my not be in the database
                // BUT they may be present anyway, if a stream was added eg from a playlist
                // of streams and coverscraper found one. So check, otherwise coverscraper
                // will search for it every time the playlist repopulated.
                if ($this->check_if_image_already_downloaded()) {
                    return true;
                }
                $disc_checked = true;
                
            }

            // Checking if the file already exists on disc doesn't help at all
            // when we're building a collection (and only slows things down).
            // If the album is already in the collection it'll have an image (or not) and
            // this will be in the database. The collection update will not change it
            // if this returns no image because we use best_value()
            
            if ($in_playlist) {
                if (!$disc_checked && $this->check_if_image_already_downloaded()) {
                    // Image may have already been downloaded if we've added the album
                    // to the Current Playlist from search results.
                } else {
                    // Different defaults for the Playlist, we'd like to be able
                    // to download images, and the information we get in the playlist
                    // from Mopidy, anyway, which is where these matter, is more complete
                    // than what comes from search results which often lack any sort
                    // of useful information.
                    switch ($domain) {
                        case 'bassdrive':
                        // case 'dirble':
                        case 'internetarchive':
                        case 'oe1':
                        // case 'podcast':
                        // case 'radio-de':
                        case 'soundcloud':
                        // case 'tunein':
                        case 'youtube':
                            debuglog("  Setting Default Image","ALBUMIMAGE");
                            $this->images = $this->image_paths_from_base_image('newimages/'.$domain.'-logo.svg');
                            break;
                    }
                }
                return true;
            }
            
            if ($doing_search) {
                if (!$disc_checked && $this->check_if_image_already_downloaded()) {
                    // We may have searched for it before
                    return true;
                }
            }
            
            switch ($domain) {
                case 'bassdrive':
                case 'dirble':
                case 'internetarchive':
                case 'oe1':
                case 'podcast':
                case 'radio-de':
                case 'soundcloud':
                case 'tunein':
                case 'youtube':
                    debuglog("  Setting Default Image","ALBUMIMAGE");
                    $this->images = $this->image_paths_from_base_image('newimages/'.$domain.'-logo.svg');
                    break;

            }
        }
    }
    
    private function image_paths_from_base_image($image) {
        $images = array(
            'small' => $image,
            'medium' => preg_replace('#albumart/small/#', 'albumart/medium/', $image),
            'asdownloaded' => preg_replace('#albumart/small/#', 'albumart/asdownloaded/', $image)
        );
        return $images;
    }
    
    private function image_info_from_database() {
        $this->basepath = 'albumart/';
        $info = get_imagesearch_info($this->key);
        foreach ($info as $k => $v) {
            $this->{$k} = $v;
        }
        $smallimage = $this->basepath.'small/'.$this->key.'.jpg';
        $this->images = $this->image_paths_from_base_image($smallimage);
    }
    
    protected function image_info_from_album_info() {
        switch ($this->artist) {
            case 'PLAYLIST':
                $this->key = $this->make_image_key();
                $this->basepath = 'prefs/plimages/'.$this->key.'/albumart/';
                break;
                
            case 'STREAM':
                $this->key = $this->make_image_key();
                $this->basepath = 'prefs/userstreams/'.$this->key.'/albumart/';
                break;
                
            case 'PODCAST':
                $this->key = $this->make_image_key();
                $this->basepath = 'prefs/podcasts/'.$this->albumpath.'/albumart/';
                break;
                
            default:
                $this->key = $this->make_image_key();
                $this->basepath = 'albumart/';
                break;
        }
        $smallimage = $this->basepath.'small/'.$this->key.'.jpg';
        return $this->image_paths_from_base_image($smallimage);
    }
    
    private function make_image_key() {
        $key = strtolower($this->artist.$this->album);
        return md5($key);
    }
    
}

class albumImage extends baseAlbumImage {
    
    public function set_source($src) {
        $this->source = $src;
    }
    
    public function has_source() {
        if ($this->source === null && $this->file === null && $this->base64data === null) {
            return false;
        } else {
            return true;
        }
    }
    
    public function download_image() {
        if (!$this->has_source()) {
            return false;
        }
        if ($this->source) {
            $retval = $this->download_remote_file();
        } else if ($this->file) {
            $retval = get_user_file($this->file, $this->key, $_FILES['ufile']['tmp_name']);
        } else if ($this->base64data) {
            $retval = $this->save_base64_data();
        }
        if ($retval !== false) {
            $this->image_downloaded = true;
            $retval = $this->saveImage($retval);
        }
        return $retval;
    }
    
    public function update_image_database() {
        switch ($this->artist) {
            case 'PLAYLIST';
                break;
                
            case 'STREAM':
                if ($this->image_downloaded) {
                    update_stream_image($this->album, $this->images['small']);
                }
                break;
                
            case 'PODCAST':
                if ($this->image_downloaded) {
                    update_podcast_image($this->albumpath, $this->images['small']);
                }
                break;
                
            default:
                update_image_db($this->key, $this->image_downloaded, $this->images['small']);
                break;
                
        }
    }

    public function get_artist_for_search() {
        switch ($this->artist) {
            case 'PLAYLIST':
            case 'STREAM':
                return '';
                break;

            case 'PODCAST':
            case 'Podcasts':
                return 'Podcast';
                break;
                
            default:
                return $this->artist;
                break;
        }
    }

    public function change_name($new_name) {
        switch ($this->artist) {
            case 'PLAYLIST':
                debuglog("Playlist name changing from ".$this->album." to ".$new_name,"FUCKINGHELL");
                if (file_exists($this->images['small'])) {
                    $oldbasepath = dirname($this->basepath);
                    $oldkey = $this->key;
                    $this->album = $new_name;
                    $this->images = $this->image_info_from_album_info();
                    $newbasepath = dirname($this->basepath);
                    debuglog("Renaming Playlist Image from ".$oldbasepath." to ".$newbasepath,"ALBUMIMAGE");
                    system('mv '.$oldbasepath.' '.$newbasepath);
                    foreach ($this->images as $image) {
                        $oldimage = dirname($image).'/'.$oldkey.'.jpg';
                        system('mv '.$oldimage.' '.$image);
                    }
                }
                break;
        }
    }
    
    private function saveImage($download_file) {
        $convert_path = find_executable('convert');
        foreach ($this->images as $image) {
            $dir = dirname($image);
            $size = basename($dir);
            if (file_exists($image)) {
                unlink($image);
            }
            if (!is_dir($dir)) {
                exec('mkdir -p '.$dir);
            }
            debuglog("  Creating file ".$image,"ALBUMIMAGE");
            switch ($size) {
                case 'small':
                    exec( $convert_path."convert \"".$download_file."\" -quality 75 -resize 100 -alpha remove \"".$image."\" 2>&1");
                    break;
                    
                case 'medium':
                    exec( $convert_path."convert \"".$download_file."\" -quality 70 -resize 400 -alpha remove \"".$image."\" 2>&1");
                    break;
                    
                case 'asdownloaded':
                    exec( $convert_path."convert \"".$download_file."\" -quality 90 -alpha remove \"".$image."\" 2>&1", $o);
                    break;
            }
        }
        unlink($download_file);
        return $this->images;
    }
    
    private function download_remote_file() {
        $download_file = 'prefs/temp/'.$this->key;
        $retval = $download_file;
        debuglog("   Downloading Image ".$this->source." to ".$download_file, "ALBUMIMAGE");
        if (file_exists($download_file)) {
            unlink ($download_file);
        }
        $fp = fopen($download_file, 'w');
    	$aagh = url_get_contents($this->source, ROMPR_IDSTRING, false, true, true, $fp);
    	fclose($fp);
    	if ($aagh['status'] == "200") {
    		debuglog("  .. Success", "ALBUMIMAGE");
            $content_type = $aagh['info']['content_type'];
        	debuglog("  .. Content Type is ".$content_type,"ALBUMIMAGE");
            if (substr($content_type,0,5) != 'image') {
        		debuglog("  .. Not an image file! ".$this->source,"ALBUMIMAGE");
                $retval = false;
            }
    	} else {
    		debuglog("Failed to download ".$this->source." - status was ".$aagh['status'],"ALBUMIMAGE",5);
            $retval = false;
    	}
        return $retval;
    }
    
    private function save_base64_data() {
        debuglog("  Saving base64 data","ALBUMIMAGE");
        $image = explode('base64,',$this->base64data);
        $download_file = 'prefs/temp/'.$this->key;
        file_put_contents($download_file, base64_decode($image[1]));
        return $download_file;
    }
            
}

function get_image_dimensions($image) {
    global $convert_path;
    $c = $convert_path."identify \"".$image."\" 2>&1";
    $o = array();
    $r = exec($c, $o);
    $width = -1;
    $height = -1;
    if (preg_match('/ (\d+)x(\d+) /', $r, $matches)) {
        $width = $matches[1];
        $height = $matches[2];
    }
    return array('width' => $width, 'height' => $height);
}

function artist_for_image($type, $artist) {
	switch ($type) {
		// case 'podcast':
        //     if ($artist)
        
        
		case 'stream':
			$artistforimage = strtoupper($type);
			break;
			
		default:
			$artistforimage = $artist;
			break;
	}
	return $artistforimage;
}

?>
