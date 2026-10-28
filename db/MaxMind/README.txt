MaxMind database placeholder

No MMDB database is included in the public source package or standalone Release.
Configure MAXMIND_DATABASE_PATH with the absolute path of an application-owned
GeoLite2 City MMDB, or configure MaxMind credentials and explicitly run the updater.

For Composer installations, store MMDB and updater state outside vendor/, for example:
  MAXMIND_DATABASE_PATH=/srv/my-app/var/geoip/GeoLite2-City.mmdb
  MAXMIND_STATE_DIR=/srv/my-app/var/geoip/.state

Legacy relative paths are resolved from the GeoLocator package root, not the
application root, current working directory or private configuration directory.

Latitude and longitude are approximate and should not be used to identify a
particular street address or household.

See docs/INSTALLATION.md for setup and permissions.
