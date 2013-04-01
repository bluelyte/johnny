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
     * Path to destination directory for downloaded files
     * @var string
     */
    protected $downloadPath = null;

    /**
     * Filters an episode list to those with full air dates before today.
     *
     * @param array $episodes Enumerated array of associative arrays of episode data
     * @return array Filtered copy of $episodes
     */
    protected function filterEpisodes(array $episodes)
    {
        $now = mktime(0, 0, 0);
        return array_filter($episodes, function($episode) use ($now) {
                return preg_match('/^[A-z]{3}\.? [0-9]{1,2}, [0-9]{4}$/', $episode['airdate'])
                    && strtotime($episode['airdate']) < $now;
            });
    }

    /**
     * Returns a regular expression for a given episode.
     *
     * @return string
     */
    protected function getEpisodePattern($title, $season, $episode)
    {
        return '/' . preg_replace('/\s+/', '.+', preg_quote($title)) . '.+s0?' . $season . 'e0?' . $episode . '[^0-9]/i';
    }

    /**
     * Returns a list of file paths corresponding to a given episode.
     *
     * @param string $title Title of the show
     * @param int $season Season of the episode
     * @param int $episode Episode number
     * @return array List of one or more files in the download path
     *         corresponding to the episode
     */
    protected function getEpisodeFiles($title, $season, $episode)
    {
        $pattern = $this->getEpisodePattern($title, $season, $episode);
        return array_filter(
            iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->getDownloadPath()))),
            function($entry) use ($pattern) {
                return (boolean) preg_match($pattern, $entry->getPathname());
            }
        );
    }

    /**
     * Returns the results of a torrent search for a given episode.
     *
     * @param string $title Title of the show
     * @param int $season Season of the episode
     * @param int $episode Episode number
     * @return array Enumerated array of associative arrays of torrent
     *         search results
     */
    protected function getEpisodeTorrents($title, $season, $episode)
    {
        $pattern = $this->getEpisodePattern($title, $season, $episode);
        $term = $title . ' S' . str_pad($season, 2, '0', STR_PAD_LEFT) . 'E' . str_pad($episode, 2, '0', STR_PAD_LEFT);
        $response = $this->getTpbClient()->search($term);
        return array_filter($response['results'], function($result) use ($pattern) {
            return (boolean) preg_match($pattern, $result['name']);
        });
    }

    /**
     * Downloads a single episode if it does not already exist in the download
     * path.
     *
     * @param string $title Title of the show
     * @param int $season Season of the episode
     * @param int $episode Episode number
     */
    protected function downloadEpisode($title, $season, $episode)
    {
        $logger = $this->getLogger();

        $logger->debug('Checking if episode ' . $latestEpisode . ' has already been downloaded');
        $files = $this->getEpisodeFiles($title, $latestSeason, $latestEpisode);
        if ($files) {
            $logger->debug('Found episode at ' . reset($files) . ', skipping');
            return;
        }

        $logger->debug('Searching for episode torrent');
        $results = $this->getEpisodeTorrents($title, $latestSeason, $latestEpisode);
        if (!$results) {
            $logger->debug('No results found, skipping');
            return;
        }
        $result = reset($results);

        $logger->debug('Adding torrent "' . $result['name'] . '" with link "'. $result['magnetLink'] . '" to download queue');
        $this->getRemote()->addTorrents($result['magnetLink']);
    }

    /**
     * Downloads the latest episodes of the specified shows to the specified
     * download path.
     *
     * @param array $series List of IMDB identifiers for shows to download
     */
    public function downloadLatestEpisodes(array $series)
    {
        $imdb = $this->getImdbClient();
        $remote = $this->getRemote();
        $logger = $this->getLogger();

        $remote->setDownloadPath($this->getDownloadPath());
        $remote->start();

        foreach ($series as $id)
        {
            $logger->debug('Fetching show info for ID ' . $id);
            $showInfo = $imdb->getShowInfo($id);
            $title = $showInfo['title'];
            $latestSeason = $showInfo['latestSeason'];

            $logger->debug('Fetching episodes for "' . $title . '" season ' . $latestSeason);
            $episodes = $this->filterEpisodes($imdb->getSeasonEpisodes($id, $latestSeason));
            if (!$episodes) {
                $logger->debug('No latest episode found, skipping');
                continue;
            }
            $latestEpisode = max(array_keys($episodes));

            $this->downloadEpisode($title, $latestSeason, $latestEpisode);
        }

        $logger->debug('Starting torrent downloads');
        $remote->startTorrents();
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
