<?php
declare(strict_types=1);

libxml_use_internal_errors( true );

$Crawler = new Crawler();
$Crawler->DiscoverFromSearch();
$Crawler->Crawl();

class Crawler
{
	/* @var resource */
	private $CurlHandle;

	/* @var array<string, bool> */
	private array $Links = [];
	private string $Directory = __DIR__ . '/docs/';
	private string $LinksFile;
	private bool $IsCI;

	public function __construct()
	{
		$this->IsCI = \getenv( 'CI' ) !== false;
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
			return;
		}

		$Links = json_decode( file_get_contents( $this->LinksFile ) );

		if( empty( $Links ) )
		{
			$Links = [ 'home' ];
		}

		foreach( $Links as $Link )
		{
			$Link = strtolower( $Link );
			$this->Links[ $Link ] = false;
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
		$Links = new ArrayObject( $this->Links );

		foreach( $Links as $Link => $Status )
		{
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

		if( $Content->length === 0 )
		{
			$this->Err( 'Did not find content tag on ' . $Doc );
			return;
		}

		$Html = $this->DOMinnerHTML( $Content[ 0 ] );
		$Html = str_replace( '</track>', '', $Html ); // bad html
		$Html = str_replace( 'steamcdn-a.akamaihd.net', 'cdn.cloudflare.steamstatic.com', $Html ); // consistent cdn
		$Html = str_replace( 'cdn.akamai.steamstatic.com', 'cdn.cloudflare.steamstatic.com', $Html ); // cool story
		$Html = str_replace( 'media.st.dl.pinyuncloud.com', 'cdn.cloudflare.steamstatic.com', $Html ); // volvo pls
		$Html .= "\n";

		// Get title
		$Content = $XPath->query( '//div[@class="docPageTitle"]', null, false );

		if( $Content->length > 0 )
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
