<?php

/**
 * This file is part of the Weblibre
 *
 * Copyright (c) 2012 Radim Kocman (xkocma03)
 * @author  Radim Kocman 
 */

use Nette\Caching\Cache;
use Nette\Application as NA;

/**
 * Browse Calibre model
 *
 * @author  Radim Kocman
 */
final class BrowseCalibre extends BaseCalibre 
{
  
  /**
   * Set cache
   * @param string $db Database path
   */
  public function __construct($db) 
  {
    parent::__construct($db);
    
    // Cache
    $this->cache = new Cache($GLOBALS['container']->cacheStorage, 'browse');
  }
  
  
  /**
   * Complete search results about books
   * @param array $sequence Sequence of books id
   * @param int $count Number of all matched books
   * @return array Results in array
   */
  private function completeSearchResults($sequence, $count) 
  {
    // Load books info
    $books = dibi::query("
      SELECT b.id, b.title, b.has_cover, b.path,
        strftime('%d. %m. %Y', b.timestamp) timestamp,
        IFNULL(r.rating, 0) rating,
        s.name series, sc.seriescount
      FROM books b 
      LEFT JOIN books_ratings_link br ON b.id = br.book
      LEFT JOIN ratings r ON br.rating = r.id
      LEFT JOIN books_series_link bs ON b.id = bs.book
      LEFT JOIN series s ON bs.series = s.id
      LEFT JOIN (
        SELECT series, COUNT(*) seriescount
        FROM books_series_link
        GROUP BY series
      ) sc ON s.id = sc.series
      WHERE b.id IN %in
    ", $sequence)->fetchAll();
    
    // Load authors
    $authors = dibi::query("
      SELECT ba.book, a.name
      FROM books_authors_link ba JOIN authors a ON ba.author = a.id
      WHERE ba.book IN %in
      ", $sequence, "
      ORDER BY ba.book, a.sort  
    ")->fetchAll();
    
    // Load formats
    $formats = dibi::query("
      SELECT id, book, format, uncompressed_size, name
      FROM data
      WHERE book IN %in
      ", $sequence, "
      ORDER BY book, format
    ")->fetchAll();
    
    // Load tags
    $tags = dibi::query("
      SELECT bt.book, t.name
      FROM books_tags_link bt JOIN tags t ON bt.tag = t.id
      WHERE bt.book IN %in
      ", $sequence, "
      ORDER BY bt.book, t.name
    ")->fetchAll();
    
    // Complete results
    $complete['count'] = $count;
    $complete['results'] = array();
    foreach($sequence as $key => $id) {
      $complete['results'][$key]['id'] = $id;
    }
    $ids = array_flip($sequence);
    foreach($books as $book){
      foreach($book as $key => $data)
        $mb[$key] = $data;
      $mb['authors'] = NULL;
      $mb['formats'] = NULL;
      $mb['tags'] = NULL;
      $complete['results'][$ids[$book['id']]] = $mb;
    }
    foreach($authors as $author) {
      $complete['results'][$ids[$author['book']]]['authors'][] = array(
        'name' => $author['name']
      );
    }
    foreach($formats as $format) {
      $complete['results'][$ids[$format['book']]]['formats'][] = array(
        'id' => $format['id'],
        'format' => $format['format'],
        'uncompressed_size' => $format['uncompressed_size'],
        'name' => $format['name']
      );
    }
    foreach($tags as $tag) {
      $complete['results'][$ids[$tag['book']]]['tags'][] = array(
        'name' => $tag['name']
      );
    }
     
    return $complete;
  }  
  
  
  /**
   * Request execute Calibre
   * @param string sortBy Sort by form value
   * @param string search Search form value
   * @return array Sequence of books id
   * @throws Nette\Application\ApplicationException
   */
  private function requestExecuteCalibre($sortBy, $search) 
  {
    $db = " --library-path ".escapeshellarg(realpath($this->db));
    
    $sortCalibre =  array(
      'Author Sort' => 'author_sort --ascending',
      'Authors' => 'authors --ascending',
      'Date' => 'timestamp',
      'Identifiers' => 'identifiers --ascending',
      'Modified' => 'last_modified',
      'Published' => 'pubdate',
      'Publishers' => 'publisher --ascending',
      'Ratings' => 'rating',
      'Series' => 'series',
      'Size' => 'size --ascending',
      'Tags' => 'tags --ascending',
      'Title' => 'title --ascending'
    );
    
    $command = 
      "list" 
      ." -f uuid"
      ." -w 200"
      .(($search != NULL)? " -s \"".$this->envEscape($search)."\"" : "")
      ." --sort-by ".$sortCalibre[$sortBy]
      .$db;
    
    $result = $this->execute("calibredb", $command);
    
    if ($result['status'] != 0)
      throw new NA\ApplicationException("Unable request calibre.");
    
    // Handle output
    array_shift($result['output']); // Skip header
    $sequence = array();
    foreach ($result['output'] as $line) {  // For each line
      if (!empty($line)) {                  // If line is not empty
        $parseLine = explode(" ", $line);   // Parse line to words
        $id = trim($parseLine[0]);          // Get first word and strip border whitespace
        if (is_numeric($id))                // Is it number?
          $sequence[] = $id;                // Save it as book id
      }
    }
    
    return $sequence;
  }
  
  
  /**
   * Request Calibre
   * @param string sortBy Sort by form value
   * @param string search Search form value
   * @return array Sequence of books id
   */
  private function requestCalibre($sortBy, $search) 
  {
    // Load cache
    if ($this->cacheResults) {
      $key = array(
        'db' => realpath($this->db),
        'sortBy' => $sortBy,
        'search' => $search
      );
      
      $sequence = $this->cache->load($key);
      if ($sequence !== NULL)
        return $sequence;
    }
    
    // Execute Calibre
    $sequence = $this->requestExecuteCalibre($sortBy, $search);
    
    // Save cache
    if ($this->cacheResults) {
      $this->cache->save($key, $sequence, array(
        Nette\Caching\Cache::FILES => $key['db'].DIRECTORY_SEPARATOR."metadata.db"
      ));
    }
    
    return $sequence;
  }
  
  
  
  /**
   * Limit records by page
   * @param int $page Current page
   * @param int $records Number of records on page
   * @return string Sql LIMIT string
   */
  private function limitDB($page, $records) 
  {
    return "LIMIT ".(($page-1)*$records).", ".$records;
  }
  
  /**
   * Limit calibre records
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param array data Sequence of all matched books id
   * @return array Sequence of books id
   */
  private function limitCalibre($page, $records, $data) 
  {
    $sequence = array();
    $first = (($page-1) * $records);
    $last = $first + ($records - 1);
    for ($i = $first; $i <= $last; $i++) {
      if (isset($data[$i]))
        $sequence[] = $data[$i];
    }
    return $sequence;
  }
  
  
  
  /**
   * Get newest books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @return array Results in array
   */
  public function getNewestBooks($page, $records) 
  {
    $sql = "
      FROM books
      ORDER BY timestamp DESC
    ";
    
    $count = dibi::query("
      SELECT COUNT(*) count
      ".$sql."
    ")->fetch();
    
    $results = dibi::query("
      SELECT id
      ".$sql."
      ".$this->limitDB($page, $records)."
    ")->fetchAll();
    
    $sequence = array();
    foreach($results as $result) {
      $sequence[] = $result['id'];
    }
    
    return $this->completeSearchResults($sequence, $count['count']);
  }
  
  /**
   * Get all books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param string $search Search form value
   * @return array Results in array
   */
  public function getAllBooks($page, $records, $sortBy, $search) 
  {
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get authors
   * @return array Results in array
   */
  public function getAuthors() 
  {
    return dibi::query("
      SELECT a.id, a.name, COUNT(ba.book) count, AVG(r.rating) rating
      FROM authors a
      LEFT JOIN books_authors_link ba ON a.id = ba.author
      LEFT JOIN books_ratings_link br ON ba.book = br.book
      LEFT JOIN ratings r ON br.rating = r.id AND r.rating > 0
      GROUP BY a.id, a.name
      HAVING COUNT(ba.book) > 0
      ORDER BY a.sort
    ")->fetchAll();
  }
  
  /**
   * Check author
   * @param int Author id
   * @return bool
   */
  public function checkAuthor($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM authors
      WHERE id=%u
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get author name
   * @param int $id Author id
   * @return string Author name
   */
  public function getAuthorName($id) 
  {
    return dibi::query("
      SELECT name
      FROM authors
      WHERE id=%u
    ", $id)->fetchSingle();
  }
  
  /**
   * Get author search string
   * @param int $id Author id
   * @return string Search string
   */
  public function getAuthorSearch($id)
  {
    $author = $this->getAuthorName($id);
    return 'authors:"='.$this->calibreEscape($author).'"';
  }
  
  /**
   * Get author books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param int $id Author id
   * @return array Results in array
   */
  public function getAuthorBooks($page, $records, $sortBy, $id) 
  {
    $search = $this->getAuthorSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get languages
   * @return array Results in array
   */
  public function getLanguages() 
  {
    return dibi::query("
      SELECT l.id, l.lang_code, COUNT(bl.book) count, AVG(r.rating) rating
      FROM languages l
      LEFT JOIN books_languages_link bl ON l.id = bl.lang_code
      LEFT JOIN books_ratings_link br ON bl.book = br.book
      LEFT JOIN ratings r ON br.rating = r.id AND r.rating > 0
      GROUP BY l.id, l.lang_code
      HAVING COUNT(bl.book) > 0
      ORDER BY l.lang_code
    ")->fetchAll();
  }
  
  /**
   * Check language
   * @param int Language id
   * @return bool
   */
  public function checkLanguage($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM languages
      WHERE id=%u
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get language name
   * @param int $id Language id
   * @return string Language name
   */
  public function getLanguageName($id) 
  {
    return dibi::query("
      SELECT lang_code
      FROM languages
      WHERE id=%u
    ", $id)->fetchSingle();
  }
  
  /**
   * Get language search string
   * @param int $id Language id
   * @return string Search string
   */
  public function getLanguageSearch($id)
  {
    $lang = $this->getLanguageName($id);
    return 'languages:"='.$this->calibreEscape($lang).'"';
  }
  
  /**
   * Get language books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param int $id Language id
   * @return array Results in array
   */
  public function getLanguageBooks($page, $records, $sortBy, $id) 
  {   
    $search = $this->getLanguageSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get publishers
   * @return array Results in array
   */
  public function getPublishers() 
  {
    return dibi::query("
      SELECT p.id, p.name, COUNT(bp.book) count, AVG(r.rating) rating
      FROM publishers p
      LEFT JOIN books_publishers_link bp ON p.id = bp.publisher
      LEFT JOIN books_ratings_link br ON bp.book = br.book
      LEFT JOIN ratings r ON br.rating = r.id AND r.rating > 0
      GROUP BY p.id, p.name
      HAVING COUNT(bp.book) > 0
      ORDER BY p.name
    ")->fetchAll();
  }
  
  /**
   * Check publisher
   * @param int Publisher id
   * @return bool
   */
  public function checkPublisher($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM publishers
      WHERE id=%u
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get publisher name
   * @param int $id Publisher id
   * @return string Publisher name
   */
  public function getPublisherName($id) 
  {
    return dibi::query("
      SELECT name
      FROM publishers
      WHERE id=%u
    ", $id)->fetchSingle();
  }
  
  /**
   * Get publisher search string
   * @param int $id Publisher id
   * @return string Search string
   */
  public function getPublisherSearch($id)
  {
    $pub = $this->getPublisherName($id);
    return 'publisher:"='.$this->calibreEscape($pub).'"';
  }
  
  /**
   * Get publisher books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param int $id Publisher id
   * @return array Results in array
   */
  public function getPublisherBooks($page, $records, $sortBy, $id) 
  {
    $search = $this->getPublisherSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get ratings
   * @return array Results in array
   */
  public function getRatings() 
  {
    return dibi::query("
      SELECT r.id id, tab.rating rating, COUNT(tab.book) count
      FROM (
        SELECT b.id book, IFNULL(r.rating, 0) rating
        FROM books b
        LEFT JOIN books_ratings_link br ON b.id = br.book
        LEFT JOIN ratings r ON  br.rating = r.id
      ) tab
      JOIN ratings r ON tab.rating=r.rating
      GROUP BY r.id, tab.rating
      HAVING COUNT(tab.book) > 0
      ORDER BY tab.rating
    ")->fetchAll();
  }
  
  /**
   * Check rating
   * @param int Rating id
   * @return bool
   */
  public function checkRating($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM ratings
      WHERE id=%u
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get rating name
   * @param int $id Rating id
   * @return string Rating name
   */
  public function getRatingName($id) 
  {
    return dibi::query("
      SELECT rating
      FROM ratings
      WHERE id=%u
    ", $id)->fetchSingle();
  }
  
  /**
   * Get rating search string
   * @param int $id Rating id
   * @return string Search string
   */
  public function getRatingSearch($id)
  {
    $rating = $this->getRatingName($id);
    return 'rating:'.($rating/2);
  }
  
  /**
   * Get rating books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param int $id Rating id
   * @return array Results in array
   */
  public function getRatingBooks($page, $records, $sortBy, $id) 
  { 
    $search = $this->getRatingSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get series
   * @return array Results in array
   */
  public function getSeries() 
  {
    return dibi::query("
      SELECT s.id, s.name, COUNT(bs.book) count, AVG(r.rating) rating
      FROM series s
      LEFT JOIN books_series_link bs ON s.id = bs.series
      LEFT JOIN books_ratings_link br ON bs.book = br.book
      LEFT JOIN ratings r ON br.rating = r.id AND r.rating > 0
      GROUP BY s.id, s.name
      HAVING COUNT(bs.book) > 0
      ORDER BY s.sort
    ")->fetchAll();
  }
  
  /**
   * Check series
   * @param int Series id
   * @return bool
   */
  public function checkSeries($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM series
      WHERE id=%u
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get series name
   * @param int $id Series id
   * @return string Series name
   */
  public function getSeriesName($id) 
  {
    return dibi::query("
      SELECT name
      FROM series
      WHERE id=%u
    ", $id)->fetchSingle();
  }
  
  /**
   * Get series search string
   * @param int $id Series id
   * @return string Search string
   */
  public function getSeriesSearch($id)
  {
    $series = $this->getSeriesName($id);
    return 'series:"='.$this->calibreEscape($series).'"';
  }
  
  /**
   * Get series books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param int $id Series id
   * @return array Results in array
   */
  public function getSeriesBooks($page, $records, $sortBy, $id) 
  {  
    $search = $this->getSeriesSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get tags
   * @return array Results in array
   */
  public function getTags() 
  {
    return dibi::query("
      SELECT t.id, t.name, COUNT(bt.book) count, AVG(r.rating) rating
      FROM tags t
      LEFT JOIN books_tags_link bt ON t.id = bt.tag
      LEFT JOIN books_ratings_link br ON bt.book = br.book
      LEFT JOIN ratings r ON br.rating = r.id AND r.rating > 0
      GROUP BY t.id, t.name
      HAVING COUNT(bt.book) > 0
      ORDER BY t.name
    ")->fetchAll();
  }
  
  /**
   * Check tag
   * @param int Tag id
   * @return bool
   */
  public function checkTag($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM tags
      WHERE id=%u
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get tag name
   * @param int $id Tag id
   * @return string Tag name
   */
  public function getTagName($id) 
  {
    return dibi::query("
      SELECT name
      FROM tags
      WHERE id=%u
    ", $id)->fetchSingle();
  }
  
  /**
   * Get tag search string
   * @param int $id Tag id
   * @return string Search string
   */
  public function getTagSearch($id)
  {
    $tag = $this->getTagName($id);
    return 'tags:"='.$this->calibreEscape($tag).'"';
  }
  
  /**
   * Get tag books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param int $id Tag id
   * @return array Results in array
   */
  public function getTagBooks($page, $records, $sortBy, $id)
  {
    $search = $this->getTagSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get formats
   * @return array Results in array
   */
  public function getFormats() 
  {
    return dibi::query("
      SELECT d.format, COUNT(d.book) count, AVG(r.rating) rating
      FROM data d
      LEFT JOIN books_ratings_link br ON d.book = br.book
      LEFT JOIN ratings r ON br.rating = r.id AND r.rating > 0
      GROUP BY d.format
      HAVING COUNT(d.book) > 0
      ORDER BY d.format
    ")->fetchAll();
  }
  
  /**
   * Check format
   * @param string Format id
   * @return bool
   */
  public function checkFormat($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM data
      WHERE format=%s
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get format search string
   * @param string $id Format id
   * @return string Search string
   */
  public function getFormatSearch($id)
  {
    return 'formats:"='.$this->calibreEscape($id).'"';
  }
  
  /**
   * Get format books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param string $id Format id
   * @return array Results in array
   */
  public function getFormatBooks($page, $records, $sortBy, $id)
  {
    $search = $this->getFormatSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
  
  /**
   * Get identifiers
   * @return array Results in array
   */
  public function getIdentifiers() 
  {
    return dibi::query("
      SELECT i.type, COUNT(i.book) count, AVG(r.rating) rating
      FROM identifiers i
      LEFT JOIN books_ratings_link br ON i.book = br.book
      LEFT JOIN ratings r ON br.rating = r.id AND r.rating > 0
      GROUP BY i.type
      HAVING COUNT(i.book) > 0
      ORDER BY i.type
    ")->fetchAll();
  }
  
  /**
   * Check identifier
   * @param string Identifier id
   * @return bool
   */
  public function checkIdentifier($id)
  {
    $count = dibi::query("
      SELECT COUNT(*)
      FROM identifiers
      WHERE type=%s
    ", $id)->fetchSingle();
    return ($count > 0)? true : false;
  }
  
  /**
   * Get identifier search string
   * @param string $id Identifier id
   * @return string Search string
   */
  public function getIdentifierSearch($id)
  {
    return 'identifiers:"='.$this->calibreEscape($id).':"';
  }
  
  /**
   * Get identifier books
   * @param int $page Current page
   * @param int $records Number of records on page
   * @param string $sortBy Sort by form value
   * @param string $id Identifier id
   * @return array Results in array
   */
  public function getIdentifierBooks($page, $records, $sortBy, $id)
  {
    $search = $this->getIdentifierSearch($id);
    
    $data = $this->requestCalibre($sortBy, $search);
    
    $sequence = $this->limitCalibre($page, $records, $data);
    
    return $this->completeSearchResults($sequence, count($data));
  }
  
}
