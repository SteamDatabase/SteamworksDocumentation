<?php
declare(strict_types=1);

$Crawler = new Crawler();
$Crawler->DiscoverFromSearch();
$Crawler->Crawl();

class Crawler
{
	private \CurlHandle $CurlHandle;

	/** @var array<string, bool> */
	private array $Links = [];

	/** @var \SplQueue<string> */
	private \SplQueue $LinksQueue;
	private string $Directory = __DIR__ . '/docs/';
	private string $LinksFile;
	private bool $IsCI;

	public function __construct()
	{
		$this->IsCI = \getenv( 'CI' ) !== false;
		$this->LinksQueue = new \SplQueue();
		$this->LinksFile = $this->Directory . '/links.json';
		$this->LoadLinks();

		$this->CurlHandle = curl_init( );

		curl_setopt_array( $this->CurlHandle, [
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SteamDocsScraper; +https://github.com/SteamDatabase/SteamworksDocumentation)',
			CURLOPT_ENCODING       => 'gzip',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT        => 30,
		] );
	}

	public function __destruct()
	{
		curl_close( $this->CurlHandle );

		$this->SaveLinks();
	}

	public function LoadLinks() : void
	{
		if( !file_exists( $this->LinksFile ) )
		{
			throw new Exception( 'File does not exist: ' . $this->LinksFile );
		}

		$Data = file_get_contents( $this->LinksFile );

		if( $Data === false )
		{
			throw new Exception( 'Failed to read ' . $this->LinksFile );
		}

		$Links = json_decode( $Data );

		if( empty( $Links ) || !is_array( $Links ) )
		{
			$Links = [ 'home' ];
		}

		/** @var string[] $Links */

		foreach( $Links as $Link )
		{
			$Link = strtolower( $Link );

			if( isset( $this->Links[ $Link ] ) )
			{
				continue;
			}

			$this->Links[ $Link ] = false;
			$this->LinksQueue->push( $Link );
		}

		echo 'Loaded ' . count( $this->Links ) . ' known links' . PHP_EOL;
	}

	public function SaveLinks() : void
	{
		$Links = array_keys( $this->Links );
		sort( $Links );

		file_put_contents( $this->LinksFile, json_encode( $Links, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );

		echo 'Saved ' . count( $this->Links ) . ' links' . PHP_EOL;
	}

	public function Crawl() : void
	{
		while( !$this->LinksQueue->isEmpty() )
		{
			$Link = $this->LinksQueue->pop();
			$Content = $this->Fetch( $Link );
			$this->Process( $Link, $Content );
		}
	}

	public function DiscoverFromSearch( ) : void
	{
		$Queries =
		[
			'Steam',
			'optin',
		];

		if( $this->IsCI )
		{
			echo "::group::DiscoverFromSearch" . PHP_EOL;
		}

		foreach( $Queries as $Query )
		{
			$this->DiscoverFromSearchQuery( $Query );
		}

		if( $this->IsCI )
		{
			echo "::endgroup::" . PHP_EOL;
		}
	}

	public function DiscoverFromSearchQuery( string $Query ) : void
	{
		$Page = 1;

		do
		{
			echo 'Searching "' . $Query . '" offset ' . $Page . '... ';

			curl_setopt( $this->CurlHandle, CURLOPT_URL, 'https://partner.steamgames.com/doc?q=' . urlencode( $Query ) . '&start=' . $Page );

			$Content = (string)curl_exec( $this->CurlHandle );

			echo curl_getinfo( $this->CurlHandle, CURLINFO_HTTP_CODE ) . PHP_EOL;

			$Document = \Dom\HTMLDocument::createFromString( $Content, \LIBXML_COMPACT | \LIBXML_NOERROR, 'UTF-8' );

			$this->DiscoverLinks( $Document );

			if( $Document->querySelector( 'a.docSearchResultLink' ) === null )
			{
				break;
			}

			$Page += 20;
		}
		while( true );
	}

	public function Fetch( string $Doc ) : string
	{
		echo 'Fetching ' . $Doc . '... ';

		curl_setopt( $this->CurlHandle, CURLOPT_URL, 'https://partner.steamgames.com/doc/' . $Doc );

		$Content = (string)curl_exec( $this->CurlHandle );

		echo curl_getinfo( $this->CurlHandle, CURLINFO_HTTP_CODE ) . PHP_EOL;

		return $Content;
	}

	public function Process( string $Doc, string $Content ) : void
	{
		if( empty( $Content ) )
		{
			$this->Err( 'No html fetched on ' . $Doc );
			return;
		}

		$Document = \Dom\HTMLDocument::createFromString( $Content, \LIBXML_COMPACT | \LIBXML_NOERROR, 'UTF-8' );

		$this->DiscoverLinks( $Document );

		$Content = $Document->querySelector( '.documentation_bbcode' );

		if( $Content === null )
		{
			$this->Err( 'Did not find content tag on ' . $Doc );
			return;
		}

		// Cleanup html
		$Elements = $Document->getElementsByTagName( 'a' );

		foreach( $Elements as $Element )
		{
			$LinkHref = $Element->getAttribute( 'href' ) ?? '';

			if( str_starts_with( $LinkHref, 'https://partner.steamgames.com/' ) )
			{
				$Element->setAttribute( 'href', substr( $LinkHref, 30 ) );
			}

			$Element->classList->remove( 'bb_doclink', 'bb_apilink' );

			if( $Element->classList->length === 0 )
			{
				$Element->removeAttribute( 'class' );
			}
		}

		$Elements = $Document->getElementsByTagName( 'ol' );

		foreach( $Elements as $Element )
		{
			$Element->classList->remove( 'bb_ol' );

			if( $Element->classList->length === 0 )
			{
				$Element->removeAttribute( 'class' );
			}
		}

		$Elements = $Document->getElementsByTagName( 'ul' );

		foreach( $Elements as $Element )
		{
			$Element->classList->remove( 'bb_ul' );

			if( $Element->classList->length === 0 )
			{
				$Element->removeAttribute( 'class' );
			}
		}

		$Html = $Content->innerHTML;

		// Add new line after breaks, cleanup cdn subdomains
		$Html = str_replace( [
			'<br>',
			'steamcdn-a.akamaihd.net',
			'media.st.dl.pinyuncloud.com',
			'media.st.dl.eccdnx.com',
		], [
			"<br>\n",
			'cdn.steamstatic.com',
			'cdn.steamstatic.com',
			'cdn.steamstatic.com',
		], $Html );
		/** @var string */
		$Html = preg_replace( '/ id="dynamiclink_[0-9]+"/', '', $Html );
		/** @var string */
		$Html = preg_replace( '/(?<type>[a-z]+)\.[a-z]+(?<domain>\.steamstatic\.com)/', '$1$2', $Html );

		if( $Html === 'Sorry, an error occurred. Please try again later' )
		{
			$this->Err( 'An error occured on ' . $Doc );
			return;
		}

		$Html .= "\n";

		// Get title
		$Content = $Document->querySelector( '.docPageTitle' );

		if( $Content?->innerHTML !== null )
		{
			$Title = trim( $Content->innerHTML );

			if( !empty( $Title ) )
			{
				$Html = "<h1>{$Title}</h1>\n{$Html}";
			}
		}

		$FullPath = $this->Directory . $Doc . '.html';
		$Directory = dirname( $FullPath );

		if( !file_exists( $Directory ) )
		{
			mkdir( $Directory, 0755, true );
		}

		file_put_contents( $FullPath, $Html );
	}

	public function DiscoverLinks( \Dom\HTMLDocument $Document ) : void
	{
		$Links = $Document->getElementsByTagName( 'a' );

		foreach( $Links as $Link )
		{
			if( preg_match( "~/doc/(?<href>.+?)(?=#|\?|$)~S", $Link->getAttribute( 'href' ) ?? '', $Match ) === 1 )
			{
				$Doc = trim( $Match[ 'href' ], '/' );

				if( strpos( $Doc, '%' ) !== false )
				{
					$this->Err( 'New link but its bad: ' . $Doc );
					continue;
				}

				$Doc = strtolower( $Doc );

				if( !isset( $this->Links[ $Doc ] ) )
				{
					$this->Links[ $Doc ] = false;
					$this->LinksQueue->push( $Doc );

					if( $this->IsCI )
					{
						echo '::warning::New link: ' . $Doc . PHP_EOL;
					}
					else
					{
						echo 'New link: ' . $Doc . PHP_EOL;
					}
				}
			}
		}
	}

	private function Err( string $Message ) : void
	{
		if( $this->IsCI )
		{
			echo "::error::" . $Message . PHP_EOL;
		}
		else
		{
			fwrite( STDERR, $Message . PHP_EOL );
		}
	}
}
