phplib
======

Library of php classes

## Logger.class.php
### Usage
```php
<?php

require_once("Logger.class.php");

$logger = new Logger("/logs/logfile.txt");

?>
```

### Properties
#### log_dir
This is a private property that is set automatically based on the file parameter passed in to the constructor.

### Methods
#### __construct(file)
Initializes logger object based on file provided. If a path is not included in the file parameter, the directory that this class resides in will be used. If a path is provided in the file parameter provided, and the path does not exist, an attempt will be made to create it.
#### addToLog(msg)
Prepends date/time ("Y-m-d h:i:s") to value in msg, prints it and adds it to logfile.

## TVShow.class.php
### Usage

### Properties
#### episode_pattern
#### season_pattern
#### episode
#### season
#### show
#### show_string
#### valid

### Methods
#### __construct(show_string)
#### getShow
#### getSeason
#### getEpisode
#### isValid
#### cleanEpisode
#### cleanSeason
#### cleanShowName
