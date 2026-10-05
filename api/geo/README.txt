Visitor country and Indian state for fomaxo.in/admin → Analytics.
Made from the free DB-IP Lite databases (https://db-ip.com, licence CC BY 4.0):
country4.bin / country6.bin = IP to Country Lite (June 2026); in4.bin / in6.bin = the India part of IP to City Lite (December 2025).
api/track.php looks up each visit once and keeps only the country code and state, never the IP address.
Records: country = start address (4 bytes, or the first 8 bytes for IPv6) + 2-letter code; in = start + end + state number (FOMAXO_STATES order, from 1).
