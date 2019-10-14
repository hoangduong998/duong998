<?php
/**
 * MediaWiki page data importer.
 *
 * Copyright © 2003,2005 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */
use MediaWiki\MediaWikiServices;

/**
 * Imports a XML dump from a file (either from file upload, files on disk, or HTTP)
 * @ingroup SpecialPage
 */
class ImportStreamSource implements ImportSource {
	function __construct( $handle ) {
		$this->mHandle = $handle;
	}

	/**
	 * @return bool
	 */
	function atEnd() {
		return feof( $this->mHandle );
	}

	/**
	 * @return string
	 */
	function readChunk() {
		return fread( $this->mHandle, 32768 );
	}

	/**
	 * @param string $filename
	 * @return Status
	 */
	static function newFromFile( $filename ) {
		Wikimedia\suppressWarnings();
		$file = fopen( $filename, 'rt' );
		Wikimedia\restoreWarnings();
		if ( !$file ) {
			return Status::newFatal( "importcantopen" );
		}
		return Status::newGood( new ImportStreamSource( $file ) );
	}

	/**
	 * @param string $fieldname
	 * @return Status
	 */
	static function newFromUpload( $fieldname = "xmlimport" ) {
		$upload =& $_FILES[$fieldname];

		if ( $upload === null || !$upload['name'] ) {
			return Status::newFatal( 'importnofile' );
		}
		if ( !empty( $upload['error'] ) ) {
			switch ( $upload['error'] ) {
				case UPLOAD_ERR_INI_SIZE:
					// The uploaded file exceeds the upload_max_filesize directive in php.ini.
					return Status::newFatal( 'importuploaderrorsize' );
				case UPLOAD_ERR_FORM_SIZE:
					// The uploaded file exceeds the MAX_FILE_SIZE directive that
					// was specified in the HTML form.
					// FIXME This is probably never used since that directive was removed in 8e91c520?
					return Status::newFatal( 'importuploaderrorsize' );
				case UPLOAD_ERR_PARTIAL:
					// The uploaded file was only partially uploaded
					return Status::newFatal( 'importuploaderrorpartial' );
				case UPLOAD_ERR_NO_TMP_DIR:
					// Missing a temporary folder.
					return Status::newFatal( 'importuploaderrortemp' );
				// Other error codes get the generic 'importnofile' error message below
			}

		}
		$fname = $upload['tmp_name'];
		if ( is_uploaded_file( $fname ) ) {
			return self::newFromFile( $fname );
		} else {
			return Status::newFatal( 'importnofile' );
		}
	}

	/**
	 * @param string $url
	 * @param string $method
	 * @return Status
	 */
	static function newFromURL( $url, $method = 'GET' ) {
		global $wgHTTPImportTimeout;
		wfDebug( __METHOD__ . ": opening $url\n" );
		# Use the standard HTTP fetch function; it times out
		# quicker and sorts out user-agent problems which might
		# otherwise prevent importing from large sites, such
		# as the Wikimedia cluster, etc.
		$data = Http::request(
			$method,
			$url,
			[
				'followRedirects' => true,
				'timeout' => $wgHTTPImportTimeout
			],
			__METHOD__
		);
		if ( $data !== false ) {
			$file = tmpfile();
			fwrite( $file, $data );
			fflush( $file );
			fseek( $file, 0 );
			return Status::newGood( new ImportStreamSource( $file ) );
		} else {
			return Status::newFatal( 'importcantopen' );
		}
	}

	/**
	 * @param string $interwiki
	 * @param string $page
	 * @param bool $history
	 * @param bool $templates
	 * @param int $pageLinkDepth
	 * @return Status
	 */
	public static function newFromInterwiki( $interwiki, $page, $history = false,
		$templates = false, $pageLinkDepth = 0
	) {
		if ( $page == '' ) {
			return Status::newFatal( 'import-noarticle' );
		}

		# Look up the first interwiki prefix, and let the foreign site handle
		# subsequent interwiki prefixes
		$firstIwPrefix = strtok( $interwiki, ':' );
		$interwikiLookup = MediaWikiServices::getInstance()->getInterwikiLookup();
		$firstIw = $interwikiLookup->fetch( $firstIwPrefix );
		if ( !$firstIw ) {
			return Status::newFatal( 'importbadinterwiki' );
		}

		$additionalIwPrefixes = strtok( '' );
		if ( $additionalIwPrefixes ) {
			$additionalIwPrefixes .= ':';
		}
		# Have to do a DB-key replacement ourselves; otherwise spaces get
		# URL-encoded to +, which is wrong in this case. Similar to logic in
		# Title::getLocalURL
		$link = $firstIw->getURL( strtr( "${additionalIwPrefixes}Special:Export/$page",
			' ', '_' ) );

		$params = [];
		if ( $history ) {
			$params['history'] = 1;
		}
		if ( $templates ) {
			$params['templates'] = 1;
		}
		if ( $pageLinkDepth ) {
			$params['pagelink-depth'] = $pageLinkDepth;
		}

		$url = wfAppendQuery( $link, $params );
		# For interwikis, use POST to avoid redirects.
		return self::newFromURL( $url, "POST" );
	}
}
