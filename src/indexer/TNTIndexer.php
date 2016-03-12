<?php

namespace TeamTNT\Indexer;

use TeamTNT\Stemmer\PorterStemmer;
use TeamTNT\Stemmer\CroatianStemmer;
use TeamTNT\Support\Collection;
use PDO;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class TNTIndexer
{
    protected $index = null;
    protected $dbh   = null;

    public function __construct()
    {
        $this->stemmer = new PorterStemmer;
    }

    public function loadConfig($config)
    {
        $this->config = $config;
        $this->config['storage'] = rtrim($this->config['storage'], '/') . '/';
        if(!isset($this->config['driver'])) $this->config['driver'] = "";
    }

    public function getStoragePath()
    {
        return $this->config['storage'];
    }

    public function getStemmer()
    {
        return $this->stemmer;
    }

    public function setCroatianStemmer()
    {
        $this->index->exec("INSERT INTO info ( 'key', 'value') values ( 'stemmer', 'croatian')");
        $this->stemmer = new CroatianStemmer;
    }

    public function createIndex($indexName)
    {
        if(file_exists($this->config['storage'] . $indexName)) {
            unlink($this->config['storage'] . $indexName);
        }

        $this->index = new PDO('sqlite:' . $this->config['storage'] . $indexName);
        $this->index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->index->exec("CREATE TABLE IF NOT EXISTS wordlist (
                    id INTEGER PRIMARY KEY,
                    term TEXT,
                    num_hits INTEGER,
                    num_docs INTEGER)");
        $this->index->exec("CREATE UNIQUE INDEX 'main'.'index' ON wordlist ('term');");

        $this->index->exec("CREATE TABLE IF NOT EXISTS doclist (
                    term_id INTEGER,
                    doc_id INTEGER,
                    hit_count INTEGER)");

        $this->index->exec("CREATE TABLE IF NOT EXISTS info (
                    key TEXT,
                    value INTEGER)");

        $this->index->exec("CREATE INDEX IF NOT EXISTS 'main'.'index' ON 'doclist' ('term_id' COLLATE BINARY);");
        $this->setSource();
        return $this;
    }

    public function setSource()
    {
        if($this->config['driver'] == "filesystem") return;

        $this->dbh = new PDO($this->config['type'].':host='.$this->config['host'].';dbname='.$this->config['db'],
            $this->config['user'], $this->config['pass']);
        $this->dbh->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $this->dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function query($query)
    {
        $this->query = $query;
    }

    public function run()
    {
        if($this->config['driver'] == "filesystem") {
            return $this->readDocumentsFromFileSystem();
        }

        $result = $this->dbh->query($this->query);

        $counter = 0;
        $this->index->beginTransaction();
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $counter++;


            $this->processDocument(new Collection($row));

            if($counter % 1000 == 0) {
                echo "Processed $counter rows\n";
            }
            if($counter % 10000 == 0) {
                $this->index->commit();
                $this->index->beginTransaction();
                echo "Commited\n";
            }
        }
        $this->index->commit();

        $this->index->exec("INSERT INTO info ( 'key', 'value') values ( 'total_documents', $counter)");
        $this->index->exec("INSERT INTO info ( 'key', 'value') values ( 'stemmer', {$this->stemmer})");

        echo "Total rows $counter\n";
    }

    public function readDocumentsFromFileSystem()
    {
        $this->index->exec("CREATE TABLE IF NOT EXISTS filemap (
                    id INTEGER PRIMARY KEY,
                    path TEXT)");
        $path = realpath($this->config['location']);

        $objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::SELF_FIRST);
        $this->index->beginTransaction();
        $counter = 0;

        foreach($objects as $name => $object) {
            $name = str_replace($path . '/', '', $name);
            if(stringEndsWith($name, $this->config['extension']) && !in_array($name, $this->config['exclude'])) {
                $counter++;
                $file = [
                    'id'      => $counter,
                    'name'    => $name,
                    'content' => file_get_contents($object)
                ];
                $this->processDocument(new Collection($file));
                $this->index->exec("INSERT INTO filemap ( 'id', 'path') values ( $counter, '$object')");
                echo "Processed $counter " . $object . "\n";
            }
        }

        $this->index->commit();
        
        $this->index->exec("INSERT INTO info ( 'key', 'value') values ( 'total_documents', $counter)");
        $this->index->exec("INSERT INTO info ( 'key', 'value') values ( 'driver', 'filesystem')");

        echo "Total rows $counter\n";
    }

    public function processDocument($row)
    {
        $stems = $row->map(function($column, $name) {
            return $this->stemText($column);
        });

        $this->saveToIndex($stems, $row->get('id'));
    }

    public function stemText($text)
    {
        $stemmer = $this->getStemmer();
        $words = preg_split("/\P{L}+/u", trim($text));

        $stems = [];
        foreach($words as $word) {
            if(strlen($word) < 2) continue;
            $stems[] = $stemmer->stem(strtolower($word));
        }
        return $stems;
    }

    public function saveToIndex($stems, $docId)
    {
        $terms = $this->saveWordlist($stems);
        $this->saveDoclist($terms, $docId);
    }

    public function saveWordlist($stems)
    {
        $terms = [];
        $stems->map(function($column, $key) use (&$terms) {
            foreach($column as $term) {

                if(array_key_exists($term, $terms)) {
                    $terms[$term]['hits']++;
                    $terms[$term]['docs'] = 1;
                } else {
                    $terms[$term] = [
                        'hits' => 1,
                        'docs' => 1,
                        'id'   => 0
                    ];
                }
            }
        });

        $insert = "INSERT INTO wordlist (term, num_hits, num_docs) VALUES (:term, :hits, :docs)";
        $stmt = $this->index->prepare($insert);

        foreach($terms as $key => $term) {
            $stmt->bindValue(':term', $key, SQLITE3_TEXT);
            $stmt->bindValue(':hits', $term['hits'], SQLITE3_INTEGER);
            $stmt->bindValue(':docs', $term['docs'], SQLITE3_INTEGER);
            try {
                $stmt->execute();
                $terms[$key]['id'] = $this->index->lastInsertId();
            } catch (\Exception $e) {
                //we have a duplicate
                if($e->getCode() == 23000) {
                    $stmt = $this->index->prepare("SELECT * FROM wordlist WHERE term like :term");
                    $stmt->bindValue(':term', $key, SQLITE3_TEXT);
                    $stmt->execute();
                    $res = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $terms[$key]['id'] = $res['id'];
                    $term['hits'] += $res['num_hits'];
                    $term['docs'] += $res['num_docs'];
                    $insert_stmt = $this->index->prepare("UPDATE wordlist SET num_docs = :docs, num_hits = :hits WHERE term = :term");
                    $insert_stmt->bindValue(':docs', $term['docs'], SQLITE3_INTEGER);
                    $insert_stmt->bindValue(':hits', $term['hits'], SQLITE3_INTEGER);
                    $insert_stmt->bindValue(':term', $key, SQLITE3_TEXT);
                    $insert_stmt->execute();
                }
            }
        }
        return $terms;
    }

    public function saveDoclist($terms, $docId)
    {
        $insert = "INSERT INTO doclist (term_id, doc_id, hit_count) VALUES (:id, :doc, :hits)";
        $stmt = $this->index->prepare($insert);

        foreach($terms as $key => $term) {
            $stmt->bindValue(':id', $term['id'], SQLITE3_INTEGER);
            $stmt->bindValue(':doc', $docId, SQLITE3_INTEGER);
            $stmt->bindValue(':hits', $term['hits'], SQLITE3_INTEGER);
            try {
                $stmt->execute();
            } catch (\Exception $e) {
                //we have a duplicate
                echo $e->getMessage();
            }
        }
    }

}