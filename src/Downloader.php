<?php

namespace Bluelyte\Johnny;

use Bluelyte\TPB\Client\Client as TpbClient;
use Bluelyte\IMDB\Client\Client as ImdbClient;
use Bluelyte\Transmission\Remote;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Downloader
{
    /**
     * @var Bluelyte\TPB\Client\Client
     */
    protected $tpbClient;

    /**
     * @var Bluelyte\IMDB\Client\Client
     */
    protected $imdbClient;

    /**
     * @var Bluelyte\Transmission\Remote
     */
    protected $remote;

    /**
     * @var Monolog\Logger
     */
    protected $logger;

    /**
     * List of IMDB identifiers for shows to download
     * @var array
     */
    protected $series = array();

    /**
     * Path to destination directory for downloaded files
     * @var string
     */
    protected $downloadPath = null;

    /**
     * Downloads the latest episodes of the specified shows to the specified
     * download path.
     */
    public function downloadLatestEpisodes()
    {
        $series = $this->getSeries();
        $downloadPath = $this->getDownloadPath();
        $imdb = $this->getImdbClient();
        $tpb = $this->getTpbClient();
        $remote = $this->getRemote();
        $logger = $this->getLogger();

        $remote->setDownloadPath($downloadPath);
        $remote->start();

        foreach ($series as $id)
        {
            $logger->debug('Fetching show info for ID ' . $id);
            $showInfo = $imdb->getShowInfo($id);
            $title = $showInfo['title'];
            $latestSeason = $showInfo['latestSeason'];

            $logger->debug('Fetching episodes for "' . $title . '" season ' . $latestSeason);
            $episodes = $imdb->getSeasonEpisodes($id, $latestSeason);
            $now = mktime(0, 0, 0);
            $episodes = array_filter($episodes, function($episode) use ($now) {
                    return preg_match('/^[A-z]{3}\.? [0-9]{1,2}, [0-9]{4}$/', $episode['airdate'])
                        && strtotime($episode['airdate']) < $now;
                });
            if (!$episodes) {
                $logger->debug('No latest episode found, skipping');
                continue;
            }
            $latestEpisode = max(array_keys($episodes));

            $logger->debug('Checking if episode ' . $latestEpisode . ' has already been downloaded');
            $pattern = '/' . preg_replace('/\s+/', '.+', preg_quote($title)) . '.+s0?' . $latestSeason . 'e0?' . $latestEpisode . '[^0-9]/i';

            $files = array_filter(
                iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($downloadPath))),
                function($entry) use ($pattern) {
                    return (boolean) preg_match($pattern, $entry->getPathname());
                }
            );
            if ($files) {
                $logger->debug('Skipping, found episode at ' . reset($files));
                continue;
            }

            $logger->debug('Searching for episode torrent');
            $term = $title . ' S' . str_pad($latestSeason, 2, '0', STR_PAD_LEFT) . 'E' . str_pad($latestEpisode, 2, '0', STR_PAD_LEFT);
            $response = $tpb->search($term);
            $results = array_filter($response['results'], function($result) use ($pattern) {
                return (boolean) preg_match($pattern, $result['name']);
            });
            $result = reset($results);
            if (!$result) {
                $logger->debug('Skipping, no results found');
                continue;
            }

            $logger->debug('Adding torrent "' . $result['name'] . '" with link "'. $result['magnetLink'] . '" to download queue');
            $remote->addTorrents($result['magnetLink']);
        }

        $logger->debug('Starting torrent downloads');
        $remote->startTorrents();
    }

    public function getSeries()
    {
        return $this->series;
    }

    public function setSeries(array $series)
    {
        $this->series = $series;
    }

    public function setDownloadPath($downloadPath)
    {
        if (!is_dir($downloadPath) || !is_writable($downloadPath)) {
            trigger_error('Path does not exist or is not writable: ' . $downloadPath, E_USER_ERROR);
        }
        $this->downloadPath = $downloadPath;
    }

    public function getDownloadPath()
    {
        return $this->downloadPath;
    }

    public function getTpbClient()
    {
        if (!$this->tpbClient) {
            $this->tpbClient = new TpbClient();
        }
        return $this->tpbClient;
    }

    public function setTpbClient(TpbClient $tpbClient)
    {
        $this->tpbClient = $tpbClient;
        return $this;
    }

    public function getImdbClient()
    {
        if (!$this->imdbClient) {
            $this->imdbClient = new ImdbClient();
        }
        return $this->imdbClient;
    }

    public function setImdbClient(ImdbClient $imdbClient)
    {
        $this->imdbClient = $imdbClient;
        return $this;
    }

    public function getRemote()
    {
        if (!$this->remote) {
            $this->remote = new Remote();
        }
        return $this->remote;
    }

    public function setRemote(Remote $remote)
    {
        $this->remote = $remote;
        return $this;
    }

    public function getLogger()
    {
        if (!$this->logger) {
            $handler = new StreamHandler(STDERR, Logger::DEBUG);
            $handler->setFormatter(new LineFormatter("%datetime% %message%\n"));
            $this->logger = new Logger(get_class($this));
            $this->logger->pushHandler($handler);
        }
        return $this->logger;
    }

    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
    }
}
