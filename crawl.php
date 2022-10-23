<?php
declare(strict_types=1);

libxml_use_internal_errors( true );

$Crawler = new Crawler();
$Crawler->DiscoverFromSearch();
$Crawler->Crawl();

class Crawler
{
	private const CDN = 'cdn.cloudflare.steamstatic.com';

	/** @var \CurlHandle */
	private $CurlHandle;

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

			$XPath = $this->DataToXPath( $Content );
			$this->DiscoverLinks( $XPath );

			if( !$XPath->evaluate( 'boolean(//a[@class="docSearchResultLink"])', null, false ) )
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
		$XPath = $this->DataToXPath( $Content );

		$this->DiscoverLinks( $XPath );

		$Content = $XPath->query( '//div[@class="documentation_bbcode"]', null, false );

		if( $Content === false || $Content->length === 0 )
		{
			$this->Err( 'Did not find content tag on ' . $Doc );
			return;
		}

		$Html = $this->DOMinnerHTML( $Content[ 0 ] );
		$Html = str_replace( [
			'</track>',
			'<br>',
			'steamcdn-a.akamaihd.net', 
			'cdn.akamai.steamstatic.com',
			'media.st.dl.pinyuncloud.com',
			'media.st.dl.eccdnx.com',
		], [
			'',
			"<br>\n",
			self::CDN,
			self::CDN,
			self::CDN,
			self::CDN,
		], $Html );

		$Html .= "\n";

		// Get title
		$Content = $XPath->query( '//div[@class="docPageTitle"]', null, false );

		if( $Content !== false && $Content->length > 0 )
		{
			$Title = trim( $this->DOMinnerHTML( $Content[ 0 ] ) );

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

	public function DiscoverLinks( \DOMXPath $XPath ) : void
	{
		$Links = $XPath->query( '//a/@href', null, false );

		if( $Links === false )
		{
			return;
		}

		/** @var \DOMAttr $Link */
		foreach( $Links as $Link )
		{
			if( preg_match( "~/doc/(?<href>.+?)(?=#|\?|$)~S", $Link->value, $Match ) === 1 )
			{
				$Doc = trim( $Match[ 'href' ], '/' );
				$Doc = str_replace( '%3Fbeta%3D1', '', $Doc );

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

	private function DataToXPath( string $Data ) : \DOMXPath
	{
		$DOM = new DOMDocument();
		$DOM->strictErrorChecking = false;
		$DOM->loadHTML( '<?xml encoding="UTF-8">' . $Data, LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOWARNING | LIBXML_NOERROR );

		foreach( $DOM->childNodes as $Item )
		{
			if( $Item->nodeType == XML_PI_NODE )
			{
				$DOM->removeChild( $Item );
			}
		}

		$DOM->encoding = 'UTF-8';

		libxml_clear_errors();

		return new DOMXPath( $DOM );
	}

	private function DOMinnerHTML( DOMNode $Element ) : string
	{
		if( $Element->ownerDocument === null )
		{
			return '';
		}
		
		$InnerHTML = '';
		$Children  = $Element->childNodes;

		foreach( $Children as $Child )
		{
			$InnerHTML .= $Element->ownerDocument->saveHTML( $Child );
		}

		return $InnerHTML;
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
