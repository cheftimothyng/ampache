<?php
/* vim:set softtabstop=4 shiftwidth=4 expandtab: */
/**
 *
 * LICENSE: GNU General Public License, version 2 (GPLv2)
 * Copyright 2001 - 2013 Ampache.org
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License v2
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */

class Song_Preview extends database_object implements media
{
    public $id;
    public $file;
    public $artist; // artist.id (Int)
    public $title;
    public $disk;
    public $track;
    public $album_mbid;
    public $artist_mbid;
    public $type;
    public $mime;
    public $mbid; // MusicBrainz ID
    public $enabled = true;

    /**
     * Constructor
     *
     * Song Preview class
     */
    public function __construct($id = null)
    {
        if (!$id) { return false; }

        $this->id = intval($id);

        if ($info = $this->_get_info()) {
            foreach ($info as $key => $value) {
                $this->$key = $value;
            }
            $data = pathinfo($this->file);
            $this->type = strtolower($data['extension']);
            $this->mime = Song::type_to_mime($this->type);
        } else {
            $this->id = null;
            return false;
        }

        return true;

    } // constructor

    /**
     * insert
     *
     * This inserts the song preview described by the passed array
     */
    public static function insert($results)
    {
        $sql = 'INSERT INTO `song_preview` (`file`, `album_mbid`, `artist`, `title`, `disk`, `track`, `mbid`, `session`) ' .
            ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

        $db_results = Dba::write($sql, array(
            $results['file'],
            $results['album_mbid'],
            $results['artist'],
            $results['title'],
            $results['disk'],
            $results['track'],
            $results['mbid'],
            $results['session'],
        ));

        if (!$db_results) {
            debug_event('song_preview', 'Unable to insert ' . $results[''], 2);
            return false;
        }

        return Dba::insert_id();
    }

    /**
     * build_cache
     *
     * This attempts to reduce queries by asking for everything in the
     * browse all at once and storing it in the cache, this can help if the
     * db connection is the slow point.
     */
    public static function build_cache($song_ids)
    {
        if (!is_array($song_ids) || !count($song_ids)) { return false; }

        $idlist = '(' . implode(',', $song_ids) . ')';

        // Callers might have passed array(false) because they are dumb
        if ($idlist == '()') { return false; }

        // Song data cache
        $sql = 'SELECT `id`, `file`, `album_mbid`, `artist`, `title`, `disk`, `track`, `mbid` ' .
            'FROM `song_preview` ' .
            "WHERE `id` IN $idlist";
        $db_results = Dba::read($sql);

        while ($row = Dba::fetch_assoc($db_results)) {
            parent::add_to_cache('song_preview', $row['id'], $row);
            $artists[$row['artist']] = $row['artist'];
        }

        Artist::build_cache($artists);

        return true;

    } // build_cache

    /**
     * _get_info
     */
    private function _get_info()
    {
        $id = $this->id;

        if (parent::is_cached('song_preview', $id)) {
            return parent::get_from_cache('song_preview', $id);
        }

        $sql = 'SELECT `id`, `file`, `album_mbid`, `artist`, `title`, `disk`, `track`, `mbid` ' .
            'FROM `song_preview` WHERE `id` = ?';
        $db_results = Dba::read($sql, array($id));

        $results = Dba::fetch_assoc($db_results);
        if (isset($results['id'])) {
            $sql = 'SELECT `mbid` FROM `artist` WHERE `id` = ?';
            $db_results = Dba::read($sql, array($results['artist']));
            if ($artist_res = Dba::fetch_assoc($db_results)) {
                $results['artist_mbid'] = $artist_res['mbid'];
            }

            parent::add_to_cache('song_preview', $id, $results);
            return $results;
        }

        return false;
    }

    /**
     * get_artist_name
     * gets the name of $this->artist, allows passing of id
     */
    public function get_artist_name($artist_id=0)
    {
        if (!$artist_id) { $artist_id = $this->artist; }
        $artist = new Artist($artist_id);
        if ($artist->prefix)
          return $artist->prefix . " " . $artist->name;
        else
          return $artist->name;

    } // get_album_name

    /**
     * format
     * This takes the current song object
     * and does a ton of formating on it creating f_??? variables on the current
     * object
     */
    public function format()
    {
        // Format the filename
        preg_match("/^.*\/(.*?)$/",$this->file, $short);
        $this->f_file = htmlspecialchars($short[1]);

        // Format the artist name
        $this->f_artist_full = $this->get_artist_name();
        $this->f_artist = UI::truncate($this->f_artist_full, AmpConfig::get('ellipse_threshold_artist'));

        // Format the title
        $this->f_title_full = $this->title;
        $this->f_title = UI::truncate($this->title, AmpConfig::get('ellipse_threshold_title'));

        // Create Links for the different objects
        $this->link = "#";
        $this->f_link = "<a href=\"" . scrub_out($this->link) . "\" title=\"" . scrub_out($this->f_artist) . " - " . scrub_out($this->title) . "\"> " . scrub_out($this->f_title) . "</a>";
        $this->f_artist_link = "<a href=\"" . AmpConfig::get('web_path') . "/artists.php?action=show&amp;artist=" . $this->artist . "\" title=\"" . scrub_out($this->f_artist_full) . "\"> " . scrub_out($this->f_artist) . "</a>";
        $this->f_album_link = "<a href=\"" . AmpConfig::get('web_path') . "/albums.php?action=show_missing&amp;mbid=" . $this->album_mbid . "&amp;artist=" . $this->artist . "\" title=\"" . $this->f_album . "\">" . $this->f_album . "</a>";

        // Format the track (there isn't really anything to do here)
        $this->f_track = $this->track;

        return true;

    } // format

    /**
     * play_url
     * This function takes all the song information and correctly formats a
     * a stream URL taking into account the downsmapling mojo and everything
     * else, this is the true function
     */
    public static function play_url($oid, $additional_params='')
    {
        $song = new Song_Preview($oid);
        $user_id     = $GLOBALS['user']->id ? scrub_out($GLOBALS['user']->id) : '-1';
        $type        = $song->type;

        $song_name = rawurlencode($song->get_artist_name() . " - " . $song->title . "." . $type);

        $url = Stream::get_base_url() . "type=song_preview&oid=$song->id&uid=$user_id&name=$song_name";

        return Stream_URL::format($url . $additional_params);

    } // play_url

    public function get_stream_types()
    {
        return array('native');
    }

    /**
     * get_transcode_settings
     *
     * FIXME: Song Preview transcoding is not implemented
     */
    public function get_transcode_settings($target = null)
    {
        return false;
    }

    public static function get_song_previews($album_mbid)
    {
        $songs = array();

        $sql = "SELECT `id` FROM `song_preview` " .
            "WHERE `session` = ? AND `album_mbid` = ?";
        $db_results = Dba::read($sql, array(session_id(), $album_mbid));

        while ($results = Dba::fetch_assoc($db_results)) {
            $songs[] = new Song_Preview($results['id']);
        }

        return $songs;
    }

    public static function gc()
    {
        $sql = 'DELETE FROM `song_preview` USING `song_preview` ' .
            'LEFT JOIN `session` ON `session`.`id`=`song_preview`.`session` ' .
            'WHERE `session`.`id` IS NULL';
        return Dba::write($sql);
    }

} // end of song_preview class
