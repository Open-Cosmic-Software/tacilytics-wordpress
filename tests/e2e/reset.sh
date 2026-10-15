W="docker run --rm --network taci-wp-test --volumes-from taci-wp -e WORDPRESS_DB_HOST=taci-wp-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp --user 33:33 wordpress:cli-php8.3"
$W wp eval 'update_option("tacilytics_settings", Tacilytics_Settings::defaults(), false); Tacilytics_Api::flush_cache(); echo "reset ok";' 2>&1 | tail -1
