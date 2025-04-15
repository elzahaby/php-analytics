# php-analytics
A simple Analytics Script in php

## Features
- Tracks visitors (time, ip, user agent, language, url, referrer)
- Displays them using Chart.js
- Groups the Visitors by Language, Visited URL, Browser
- Possible to Filter Visitors by one of the Grouped options
- Set Periods to display: Today, Last Week, Last Month, Last Year, Overall

## Usage
1. Add anayltics.php on your server
2. use: include analytics.php; to start tracing
3. Done

4. Visit analytics.php?stats=1 to view your analytics

The Script creates for every visit a seperate file containing all Data. this ensures concurency and keeps it simple.
