<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Cache;

define( 'DBX_DIR', USER_DIR . 'plugins/dropbox/' );
define( 'DBX_TMP_DIR', USER_DIR . 'plugins/dropbox/.temp/' );
define( 'CURSOR_FILE', DBX_DIR . 'cursor' );
define( 'SYNCPATHS_FILE', DBX_DIR . 'syncpaths');

require_once DBX_DIR . 'vendor/autoload.php';

use Dropbox as dbx;

class DropboxPlugin extends Plugin
{
    private $dbxClient;
    private $cache;

    public static function getSubscribedEvents ()
    {
        return ['onPluginsInitialized' => ['onPluginsInitialized', 0]];
    }

    public function onPluginsInitialized ()
    {
        if ( $this->isAdmin() ) {
            $this->active = false;
            return;
        }

        $path = $this->grav['uri']->path();
        $route = $this->config->get('plugins.dropbox.route');

        if ($route && $route == $path) {
            $this->enable([
                'onPageInitialized' => ['onPageInitialized', 0],
            ]);
        }
    }

    public function onPageInitialized ()
    {
        $this->grav['log']->warning('Dropbox Initialized');

        define( 'CACHE_ENABLED', $this->config->get('system.cache.enabled') );
        if ( CACHE_ENABLED === true ) {
            $this->cache = $this->grav['cache'];
        }

        define( 'DBX_APP_KEY', $this->config->get('plugins.dropbox.app.key') );
        define( 'DBX_APP_SECRET', $this->config->get('plugins.dropbox.app.secret') );
        define( 'DBX_APP_TOKEN', $this->config->get('plugins.dropbox.app.token') );

        define( 'DBX_SYNC_REMOTE', $this->config->get('plugins.dropbox.sync.remote') );
        define( 'DBX_SYNC_LOCAL', DBX_DIR . $this->config->get('plugins.dropbox.sync.local') );

        $request = getenv('REQUEST_METHOD');
        if ( $request === null ) {
            // TODO: 404;
        } else {
            switch ( $request ) {
                case 'GET':
                    // Challenges and authentication
                    if ( isset( $_GET['challenge'] ) && !empty( $_GET['challenge'] ) ) {
                        echo $_GET['challenge'];
                        exit;
                    }
                    // Manually check for changed files
                    elseif ( in_array( getenv('REMOTE_ADDR'), array( "127.0.0.7", "::1" ) ) ) {
                        if ( $this->getClient() !== false ) {
                            $this->youreMine();
                        }
                    }
                    // 404 for non-local clients that aren't challenging
                    else {
                        // TODO: 404;
                    }
                    break;
                case 'POST':
                    // Get notifications for changed files
                    if ( $this->signedSealed() === true && $this->getClient() === true ){
                            $this->delivered();
                    }
                    // 403 for unauthorized posts
                    else {
                        // TODO: 403;
                    }
                    break;
            }
        }
    }

    // Check if post is made by Dropbox
    private function signedSealed()
    {
        $signature = getenv('HTTP_X_DROPBOX_SIGNATURE');
        if ( $signature === null || empty( $signature ) ){
            return false;
        } else {
            $httpbody = file_get_contents('php://input');
            $hmac = hash_hmac( 'sha256', $httpbody, DBX_APP_SECRET );
            if ( dbx\Security::stringEquals( $signature, $hmac ) ) {
                return true;
            } else {
                return false;
            }
        }
    }

    // Check Dropbox notification for changed files based on hash
    private function delivered()
    {
        $cursor = null;
        $has_more = true;
        $contents = array();
        if ( CACHE_ENABLED === true ) {
            $dbx_cache_id = md5( "dbxCursor" . DBX_SYNC_REMOTE );
            list( $cursor ) = $this->cache->fetch( $dbx_cache_id );
            while ( $has_more ) {
                $delta = $this->dbxClient->getDelta( $cursor, DBX_SYNC_REMOTE );
                foreach ( $delta['entries'] as $entry ) {
                    if( $entry[1] === null ) {
                        $contents[] = array( $entry[0], null, null );
                    } else {
                        $contents[] = array( $entry[0], $entry[1]['modified'], $entry[1]['is_dir'], $entry[1]['rev'] );
                    }
                }
                $this->imYours( $contents, DBX_SYNC_REMOTE ) ;
                $cursor = $delta['cursor'];
                $this->cache->save( $dbx_cache_id, array( $cursor ) );
                $has_more = $delta['has_more'];
            }
        } else {
            if ( file_exists( CURSOR_FILE ) && file_get_contents( CURSOR_FILE ) !== '' ) {
                $cursor = file_get_contents( CURSOR_FILE );
                while ( $has_more ) {
                    $delta = $this->dbxClient->getDelta( $cursor, DBX_SYNC_REMOTE );
                    if( $entry[1] === null ) {
                        $contents[] = array( $entry[0], null, null );
                    } else {
                        $contents[] = array( $entry[0], $entry[1]['modified'], $entry[1]['is_dir'], $entry[1]['rev'] );
                    }
                    $this->imYours( $contents ) ;
                    $cursor = $delta['cursor'];
                    file_put_contents( CURSOR_FILE, $cursor );
                    $has_more = $delta['has_more'];
                }
            }
        }
    }

    // Check cache for changed files and download
    private function imYours( $contents )
    {
        if ( CACHE_ENABLED === true ) {
            foreach( $contents as $content ){
                $dbx_cache_id = md5( "dbxContent" . $content[0] );
                // $this->grav['log']->warning( "imYours rev: " . $content[3] );
                if( $content[1] !== null ) {
                    list( $object, $mtime, $dir ) = $this->cache->fetch( $dbx_cache_id );
                    if ( $object === null || $object !== null && strtotime( $mtime ) < strtotime( $content[1] ) ) {
                        $content[1] = $this->getFile( $content );
                        $this->grav['log']->warning( "imYours new mtime after dl: " . var_export($content, true) );
                        $this->cache->save( $dbx_cache_id, $content );
                    }
                } else {
                    $this->deleteLocalFile( $content[0] );
                    if( $this->cache->fetch( $dbx_cache_id ) !== null ){
                        $this->cache->save( $dbx_cache_id, array( null, null, null, null ) );
                    }
                }
            }
        } else {
            foreach( $contents as $content ){
                if( $content[1] !== null ) {
                    $this->getFile( $content );
                } else {
                    $this->deleteLocalFile( $content[0] );
                }
            }
        }
        $this->youreMine();
    }

    // Check cache for changed files and upload
    private function youreMine()
    {
        $contents = $this->recurse();
        if ( CACHE_ENABLED === true ) {
            foreach( $contents as $content ){
                $dbx_cache_id = md5( "dbxContent" . $content[0] );
                $this->grav['log']->warning( "youreMine " . var_export($content[0], true) . " = " . $dbx_cache_id );
                list( $object, $mtime, $dir, $rev ) = $this->cache->fetch( $dbx_cache_id );
                $this->grav['log']->warning( "object=$object, mtime=$mtime, dir=$dir, rev=$rev" );
                $this->grav['log']->warning( $mtime . " < " . $content[1] . " = " . ( $mtime < $content[1] ) );
                if ( $object === null ) {
                    $this->uploadFile( $content );
                    $this->cache->save( $dbx_cache_id, $content );
                } elseif ( $object !== null && $mtime < $content[1]  ) {
                    $this->uploadFile( $content, $rev );
                    $this->cache->save( $dbx_cache_id, $content );
                }
            }
        } else {
            foreach( $contents as $content ){
                $this->uploadFile( $content );
            }
        }
    }

    private function recurse(){
        $localSyncPaths = array();
        $contents = array();
        $objects = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( DBX_SYNC_LOCAL ) );
        if ( CACHE_ENABLED === true ){
            $dbx_cache_id = md5( "localContent" );
            list( $oldLocalSyncPaths ) = $this->cache->fetch( $dbx_cache_id );
        } else {
            if ( file_exists( SYNCPATHS_FILE ) ) {
                $oldLocalSyncPaths = json_decode ( file_get_contents( SYNCPATHS_FILE ) );
            }
        }
        //$this->grav['log']->warning( "Before oldLocalSyncPaths = " . var_export($oldLocalSyncPaths, true) );
        foreach ( $objects as $object ) {
            clearstatcache( true, $object );
            if ( $object->isDir() ) {
                $dir = true;
                if ( count( glob( "$object/*", GLOB_NOSORT ) ) === 0 ) {
                    $object = rtrim( $object, "/." );
                } else {
                    continue;
                }
            } else {
                $dir = false;
            }
            $mtime = stat( $object )['mtime'];
            $this->grav['log']->warning( basename($object) . " mtime = " . var_export($mtime, true) );
            $object = str_replace( DBX_SYNC_LOCAL, '', $object);
            $localSyncPaths[] = $object;
            if( $oldLocalSyncPaths !== null && isset( $oldLocalSyncPaths[0] ) && $oldLocalSyncPaths[0] !== '' ) {
                $needle = $object;
                $found = false;
                while ( $found === false ) {
                    //$this->grav['log']->warning( "array_search for " . $needle . " inside of " . var_export($oldLocalSyncPaths,true) );
                    $found = array_search( $needle, $oldLocalSyncPaths );
                    if( $found !== false ) {
                        unset( $oldLocalSyncPaths[ $found ] );
                    }
                    if( $needle !== dirname( $object ) ) {
                        $needle = dirname( $object );
                    } else {
                        break;
                    }
                }
            }
            if( !empty( $object ) ) {
                $this->grav['log']->warning( "Object added to contents:\n " . var_export( array( $object, $mtime, $dir, null ), true ) );
                $contents[] = array( $object, $mtime, $dir, null );
            }
        }
        if( $oldLocalSyncPaths !== null && isset( $oldLocalSyncPaths[0] ) && $oldLocalSyncPaths[0] !== '' ) {
            //$this->grav['log']->warning( "After oldLocalSyncPaths = " . var_export($oldLocalSyncPaths, true) );
            foreach( $oldLocalSyncPaths as $oldSyncPath ) {
                $this->deleteRemoteFile( $oldSyncPath );
            }
        }
        if ( CACHE_ENABLED === true ){
            $this->cache->save( $dbx_cache_id, array( $localSyncPaths ) );
        } else {
            if ( !file_exists( SYNCPATHS_FILE ) ) {
                file_put_contents( SYNCPATHS_FILE, json_encode( $localSyncPaths ) );
            }
        }
        return $contents;
    }

    private function getFile ( $content )
    {
        $this->grav['log']->warning( "getFile " . $content[0] );
        $localSyncPath = DBX_SYNC_LOCAL . $content[0];
        if ( $content[2] === false ){
            $remoteSyncPath = DBX_SYNC_REMOTE . $content[0];
            $tempLocalPath = DBX_TMP_DIR . basename( $content[0] );
            $parentSyncPath = dirname( $localSyncPath );
            if ( !file_exists( $parentSyncPath ) ) {
                mkdir( $parentSyncPath, 0777, true );
            }
            $f = fopen( $tempLocalPath, 'w+b' );
            $this->dbxClient->getFile( $remoteSyncPath, $f );
            fclose( $f );
            rename( $tempLocalPath, $localSyncPath );
        } else {
            if ( !file_exists( $localSyncPath ) ) {
                mkdir( $localSyncPath, 0777, true );
            }
        }
        return stat( $localSyncPath )['mtime'];
    }

    private function uploadFile ( $content, $rev = false )
    {
        $this->grav['log']->warning( "uploadFile " . $content[0] );
        $remoteSyncPath = DBX_SYNC_REMOTE . $content[0];
        $localSyncPath = DBX_SYNC_LOCAL . $content[0];
        $pathError = dbx\Path::findError( $remoteSyncPath );
        if ( $pathError !== null ) {
            $this->grav['log']->warning("Dropbox remote sync path error: $pathError");
        } else {
            if ( $content[2] === false ) {
                $size = null;
                if ( stream_is_local( $localSyncPath ) ) {
                    $size = filesize( $localSyncPath );
                }
                $writeMode = dbx\WriteMode::add();
                $this->grav['log']->warning("Rev: $rev");
                if( $rev !== false && $rev !== null ){
                    $writeMode = dbx\WriteMode::update( $rev );
                }
                $f = fopen( $localSyncPath, "rb" );
                $metadat = $this->dbxClient->uploadFile( $remoteSyncPath, $writeMode, $f, $size );
                fclose( $f );
                // TODO: check metadata for if file was renamed to prevent file feedback loop
            } else {
                $this->dbxClient->createFolder( $remoteSyncPath );
            }
        }
    }

    private function deleteLocalFile ( $content )
    {
        $this->grav['log']->warning( "deleteLocalFile " . $content );
        $object = DBX_SYNC_LOCAL . $content;
        if ( file_exists( $object ) ) {
            if( is_dir( $object ) ){
                rmdir( $object );
            } else {
                unlink( $object );
            }
        }
    }

    private function deleteRemoteFile ( $content )
    {
        $this->grav['log']->warning( "deleteRemoteFile " . $content );
        $object = DBX_SYNC_REMOTE . $content;
        if( $object !== '/' && !empty( $object ) ) {
            $metadata = $this->dbxClient->getMetadata( $object );
            if( $metadata !== null ) {
                $this->dbxClient->delete( $object );
            }
        }
    }

    // Create AppInfo to getClient
    private function getAppConfig ()
    {
        try {
            $appInfo = new dbx\AppInfo( DBX_APP_KEY, DBX_APP_SECRET );
        }
        catch (dbx\AppInfoLoadException $ex) {
            $this->grav['log']->error("Dropbox configuration unable to load: " . $ex->getMessage());
        }
        return $appInfo;
    }

    // Try to set dbxClient
    private function getClient ()
    {
        if ( empty( DBX_APP_TOKEN ) ) {
            $this->dbxClient = false;
            $this->grav['log']->warning("Dropbox Oauth2 token isn't set: Please get one from https://dropbox.com/developers/apps.");
            return false;
        }
        $appInfo = $this->getAppConfig();
        $clientIdentifier = getenv('HTTP_HOST');
        $userLocale = null;
        $this->dbxClient = new dbx\Client( DBX_APP_TOKEN, $clientIdentifier, $userLocale, $appInfo->getHost() );
        if( $this->dbxClient !== false ) {
            return true;
        } else {
            $this->grav['log']->warning("Dropbox couldn't authorize client.");
            // TODO: send email alert (failed attempt to authorize);
            return false;
        }
    }
}
