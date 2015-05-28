"BBStats" - a BlackBerry Vendor Portal Data Export Automation Service
=====================================================================

What is this?
-------------
A http client written in php to automate the export of BlackBerry Vendor Portal reports. This does not include any visualization.

It´s meant to work like this:

> Put your username and password in and get (nice and meaningful) data out.

Why?
----
Reason A:
> BlackBerrys "Vendor Portal" reports are suboptimal from a modern developer perspective (e.g.: no "total-daily-revenue" chart).

Reason B:
> Data aggregation services like "Distimo" (now "AppAnnie") have dropped support for BlackBerry after BB has introduced the new backend in 2014. Thus we'll have to do it on our own (or point them to this project and hope they integrate it).

Reason C:
> I love my daily reports to be complete! This means they have to include the data from ALL stores - not "most" of them.

How to use it?
---------------
As always, docs are the last thing on the ToDo list. A look into the code should give you a good start (look for public methods in src/BlackBerryStats.php aka vendor/geoathome/bbstats/src/BlackBerryStats.php).


Features
--------
  * login / logout
  * list all apps with name and app id
  * schedule reports ( Downloads, Downloads_Summary, Subscription, Purchases, Reviews )
  * list all available reports
  * download reports (download > unzip > extract csv to ASSOC array)
  * delete reports

Examples
----------

A) Get all purchases of the last 25 days for one specific app:
<pre>
$reportRangeInDays = 14; // 2 weeks back
$username = "user@example.org";
$password = "supersecretpassword";
$numericAppId = 12345678;

// Init the service (you may also use "$stats = new BlackBerryStats()" in a none symfony context)
$stats = $this->get('bbstats');

// Login (takes a few seconds)
$stats->login( $username, $password );

// schedule a report
$stats->scheduleReport( $numericAppId, BlackBerryStats::REPORT_TYPE_PURCHASES, $reportRangeInDays * -1 );

// wait for BlackBerry to actually create the report
sleep(3);

// fetch a list of all available reports (newest are first)
$reports = $stats->getReports();

// download and "interpret" the newest report data
$reportData = $stats->downloadReport( $reports[0], null, true );
</pre>
	
Installation Requirements
----------------------------
  * Symfony >= 2.3 is optional - (http://symfony.com/download, http://symfony.com/doc/2.3/book/installation.html)
  * Guzzle 5.3.0 (https://github.com/guzzle/guzzle or https://guzzle.readthedocs.org)
  * PHP >= 5.4.0
  * PHP - Curl support
  * PHP - ZipArchive class support

Symfony Service Configuration (services.yml):

<pre>
services:
  bbstats:
    class:  GeoAtHome\BBStats\BlackBerryStats
    arguments:
        tmpDir : "%kernel.root_dir%/../tmp/"
</pre>

Use Composer to install the bundle:

<pre>
{
    "repositories": [
        {
            "url": "https://github.com/geo-at-github/bbstats.git",
            "type": "git"
        }
    ],
    "require": {
        "geoathome/bbstats": "1.1.*"
    }
}
</pre>