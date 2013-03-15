# bluelyte/johnny

A library that interacts with IMDB, The Pirate Bay, and the transmission-remote CLI client to automate torrenting TV show episodes.

DISCLAIMER: This project is not endorsed by, affiliated with, or intended to infringe upon IMDB, The Pirate Bay, or the Transmission project and is meant for non-commercial purposes (i.e. personal use) only.

# Install

The recommended method of installation is [through composer](http://getcomposer.org/).

```JSON
{
    "require": {
        "bluelyte/johnny": "1.0.0"
    }
}
```

# Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$downloader = new \Bluelyte\Johnny\Downloader;
$downloader->setDownloadPath('/home/username/Downloads');
$downloader->setSeries(array(
    'tt0898266', // The Big Bang Theory (http://www.imdb.com/title/tt0898266/)
    'tt0433309', // Numb3rs (http://www.imdb.com/title/tt0433309/)
));
$downloader->downloadLatestEpisodes();
```

## License

Released under the BSD License. See `LICENSE`.
