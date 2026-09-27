<?php
declare(strict_types=1);
require_once __DIR__ . '/RemoteFetcher.php';

final class AuthorityDataException extends RuntimeException {}

/** Fetches structured records only from administrator-registered, host-pinned authority APIs. */
final class AuthorityDataService
{
    private const ADAPTER_VERSION='authority-adapters-2026-09-27';

    public function __construct(private PDO $pdo) {}

    public function fetch(int $sourceId, string $identifier): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM authority_sources WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$sourceId]);
        $source = $stmt->fetch();
        if (!$source) throw new AuthorityDataException('The selected authority source is not active.');
        $identifier = trim($identifier);
        $this->validateIdentifier((string) $source['adapter'], $identifier);
        if($source['adapter']==='openalex'&&str_contains($identifier,'/'))$identifier=basename($identifier);
        if($source['adapter']==='worldbank_indicator'){
            [$country,$indicator]=explode(':',$identifier,2);
            $url=str_replace(['{country}','{indicator}'],[rawurlencode(strtoupper($country)),rawurlencode(strtoupper($indicator))],(string)$source['base_url']);
        }else $url=str_replace('{id}',rawurlencode($identifier),(string)$source['base_url']);
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== mb_strtolower((string) $source['allowed_host']) || !str_starts_with($url, 'https://')) {
            throw new AuthorityDataException('Authority endpoint host validation failed.');
        }
        $json = (new RemoteFetcher())->fetchJson($url, 5 * 1024 * 1024, $host);
        $record = match ($source['adapter']) {
            'wikidata' => $this->wikidata($json, $identifier),
            'gbif' => $this->gbif($json),
            'openalex' => $this->openAlex($json),
            'crossref' => $this->crossref($json),
            'usgs' => $this->usgs($json),
            'worldbank' => $this->worldBank($json),
            'worldbank_indicator' => $this->worldBankIndicator($json),
            'nasa_exoplanet' => $this->nasaExoplanet($json),
            default => throw new AuthorityDataException('This authority adapter is not supported.'),
        };
        if (mb_strlen((string) ($record['title'] ?? '')) < 2 || mb_strlen((string) ($record['description'] ?? '')) < 20) {
            throw new AuthorityDataException('The authority record lacks enough structured information for an article draft.');
        }
        $record['authority_source_id'] = (int) $source['id'];
        $record['authority_name'] = $source['name'];
        $record['authority_license'] = $source['license_name'];
        $record['authority_license_url'] = $source['license_url'];
        $record['external_identifier'] = $identifier;
        $record['record_url'] = $url;
        $record['record_hash']=hash('sha256',json_encode($json,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $record['adapter_version']=self::ADAPTER_VERSION;
        $record['sources'] = [['url' => $url, 'label' => $source['name'] . ' record ' . $identifier]];
        return $record;
    }

    private function validateIdentifier(string $adapter, string $identifier): void
    {
        $valid = match ($adapter) {
            'wikidata' => preg_match('/^Q[1-9][0-9]{0,14}$/i', $identifier),
            'gbif' => preg_match('/^[1-9][0-9]{0,14}$/', $identifier),
            'openalex' => preg_match('/^(?:https:\/\/openalex\.org\/)?[A-Z][0-9]{4,15}$/i', $identifier),
            'crossref' => preg_match('~^10\.[0-9]{4,9}/[-._;()/:A-Z0-9]+$~i', $identifier),
            'usgs' => preg_match('/^[a-z0-9_-]{4,40}$/i', $identifier),
            'worldbank' => preg_match('/^[A-Z]{2,3}$/i', $identifier),
            'worldbank_indicator' => preg_match('/^[A-Z]{2,3}:[A-Z0-9.]{3,40}$/i',$identifier),
            'nasa_exoplanet' => preg_match('/^[A-Z0-9][A-Z0-9 .+_-]{1,99}$/i', $identifier),
            default => false,
        };
        if (!$valid) throw new AuthorityDataException('The external record identifier has an invalid format for this source.');
    }

    private function wikidata(array $json, string $id): array
    {
        $entity = $json['entities'][strtoupper($id)] ?? $json['entities'][$id] ?? null;
        if (!is_array($entity) || isset($entity['missing'])) throw new AuthorityDataException('Wikidata entity not found.');
        $label = $entity['labels']['bn']['value'] ?? $entity['labels']['en']['value'] ?? $id;
        $description = $entity['descriptions']['bn']['value'] ?? $entity['descriptions']['en']['value'] ?? 'Structured entity described by Wikidata.';
        $facts = ['Wikidata ID' => strtoupper($id)];
        foreach (['P31' => 'Instance of', 'P571' => 'Inception', 'P625' => 'Coordinates', 'P1082' => 'Population', 'P2046' => 'Area'] as $property => $name) {
            $value = $entity['claims'][$property][0]['mainsnak']['datavalue']['value'] ?? null;
            if (is_array($value) && isset($value['time'])) $value = ltrim(substr((string) $value['time'], 0, 11), '+');
            elseif (is_array($value) && isset($value['latitude'], $value['longitude'])) $value = $value['latitude'] . ', ' . $value['longitude'];
            elseif (is_array($value) && isset($value['amount'])) $value = ltrim((string) $value['amount'], '+');
            elseif (is_array($value) && isset($value['id'])) $value = $value['id'];
            if (is_scalar($value) && (string) $value !== '') $facts[$name] = (string) $value;
        }
        return ['title'=>$label,'description'=>rtrim(ucfirst($description),'. ').'.','article_type'=>'Wikidata entity','overview'=>rtrim($description,'. ').'.', 'facts' => $facts, 'categories' => ['Wikidata-backed articles']];
    }

    private function gbif(array $j): array
    {
        $title = $j['canonicalName'] ?? $j['scientificName'] ?? '';
        $facts = array_filter(['Scientific name' => $j['scientificName'] ?? null, 'Kingdom' => $j['kingdom'] ?? null, 'Phylum' => $j['phylum'] ?? null, 'Class' => $j['class'] ?? null, 'Order' => $j['order'] ?? null, 'Family' => $j['family'] ?? null, 'Genus' => $j['genus'] ?? null, 'Taxonomic status' => $j['taxonomicStatus'] ?? null]);
        $description = $title . ' is a taxon represented in the GBIF taxonomic backbone';
        return ['title' => $title, 'description' => $description . '.', 'article_type' => 'Taxon', 'overview' => $description . '.', 'facts' => $facts, 'categories' => ['Taxonomy', 'GBIF-backed articles']];
    }

    private function openAlex(array $j): array
    {
        $title = (string) ($j['display_name'] ?? $j['title'] ?? '');
        $authors = array_slice(array_filter(array_map(static fn($a) => $a['author']['display_name'] ?? null, $j['authorships'] ?? [])), 0, 12);
        $facts = array_filter(['OpenAlex ID' => $j['id'] ?? null, 'Publication year' => $j['publication_year'] ?? null, 'Type' => $j['type'] ?? null, 'DOI' => $j['doi'] ?? null, 'Cited by' => $j['cited_by_count'] ?? null, 'Authors' => implode(', ', $authors), 'Venue' => $j['primary_location']['source']['display_name'] ?? null]);
        $description = $title . ' is a scholarly work indexed by OpenAlex';
        return ['title' => $title, 'description' => $description . '.', 'article_type' => 'Scholarly work', 'overview' => $description . '.', 'facts' => $facts, 'categories' => ['Scholarly works', 'OpenAlex-backed articles']];
    }

    private function crossref(array $json): array
    {
        $j = $json['message'] ?? [];
        $title = (string) (($j['title'][0] ?? null) ?: '');
        $facts = array_filter(['DOI' => $j['DOI'] ?? null, 'Type' => $j['type'] ?? null, 'Publisher' => $j['publisher'] ?? null, 'Journal' => $j['container-title'][0] ?? null, 'Published' => isset($j['published']['date-parts'][0]) ? implode('-', $j['published']['date-parts'][0]) : null, 'URL' => $j['URL'] ?? null]);
        $description = $title . ' is a registered scholarly publication described by Crossref metadata';
        return ['title' => $title, 'description' => $description . '.', 'article_type' => 'Scholarly publication', 'overview' => $description . '.', 'facts' => $facts, 'categories' => ['Scholarly publications', 'Crossref-backed articles']];
    }

    private function usgs(array $j): array
    {
        $feature = ($j['type'] ?? '') === 'FeatureCollection' ? ($j['features'][0] ?? []) : $j;
        $p = $feature['properties'] ?? [];
        $coords = $feature['geometry']['coordinates'] ?? [];
        $title = (string) ($p['title'] ?? '');
        $facts = array_filter(['Magnitude' => $p['mag'] ?? null, 'Place' => $p['place'] ?? null, 'Time' => isset($p['time']) ? gmdate('Y-m-d H:i:s', (int) ($p['time'] / 1000)) . ' UTC' : null, 'Depth' => isset($coords[2]) ? $coords[2] . ' km' : null, 'Coordinates' => isset($coords[1], $coords[0]) ? $coords[1] . ', ' . $coords[0] : null, 'Significance' => $p['sig'] ?? null, 'USGS status' => $p['status'] ?? null]);
        $description = $title . ' is an earthquake event recorded by the United States Geological Survey';
        return ['title' => $title, 'description' => $description . '.', 'article_type' => 'Earthquake event', 'overview' => $description . '.', 'facts' => $facts, 'categories' => ['Earthquakes', 'USGS-backed articles']];
    }

    private function worldBank(array $j): array
    {
        $record=$j[1][0]??null;if(!is_array($record))throw new AuthorityDataException('World Bank country record not found.');
        $title=(string)($record['name']??'');
        $facts=array_filter(['ISO code'=>$record['id']??null,'Capital city'=>$record['capitalCity']??null,'Region'=>$record['region']['value']??null,'Income level'=>$record['incomeLevel']['value']??null,'Lending type'=>$record['lendingType']['value']??null,'Longitude'=>$record['longitude']??null,'Latitude'=>$record['latitude']??null],static fn($value):bool=>$value!==null&&$value!=='');
        $description=$title.' is a country or economy represented in the World Bank geographic and statistical catalog';
        return ['title'=>$title,'description'=>$description.'.','article_type'=>'Country or economy','overview'=>$description.'. Values reflect the authority record at retrieval time.','facts'=>$facts,'categories'=>['Countries and economies','World Bank-backed articles'],'direct_publication_eligible'=>!in_array((string)($record['region']['id']??''),['','NA'],true)];
    }

    private function worldBankIndicator(array $j): array
    {
        $record=$j[1][0]??null;if(!is_array($record))throw new AuthorityDataException('World Bank indicator observation not found.');
        $indicator=(string)($record['indicator']['value']??'');$country=(string)($record['country']['value']??'');$year=(string)($record['date']??'');$value=$record['value']??null;
        if($value===null||$value==='')throw new AuthorityDataException('The latest World Bank indicator observation has no value.');
        $title=trim($indicator.' in '.$country);
        $facts=array_filter(['Country or economy'=>$country,'Country code'=>$record['countryiso3code']??null,'Indicator'=>$indicator,'Indicator code'=>$record['indicator']['id']??null,'Observation year'=>$year,'Value'=>(string)$value,'Unit'=>$record['unit']??null,'Observation status'=>$record['obs_status']??null],static fn($item):bool=>$item!==null&&$item!=='');
        $description=$title.' is a statistical observation published through the World Bank Open Data API';
        return ['title'=>$title,'description'=>$description.'.','article_type'=>'Statistical observation','overview'=>$description.'. The value shown is for '.$year.' and must be interpreted with the indicator methodology.','facts'=>$facts,'categories'=>['Statistics','World Bank indicator records']];
    }

    private function nasaExoplanet(array $j): array
    {
        $record=$j[0]??null;if(!is_array($record))throw new AuthorityDataException('NASA Exoplanet Archive record not found.');
        $title=(string)($record['pl_name']??'');
        $facts=array_filter(['Host star'=>$record['hostname']??null,'Discovery year'=>$record['disc_year']??null,'Discovery method'=>$record['discoverymethod']??null,'Orbital period (days)'=>$record['pl_orbper']??null,'Planet radius (Earth radii)'=>$record['pl_rade']??null,'Planet mass (Earth masses)'=>$record['pl_bmasse']??null,'Equilibrium temperature (K)'=>$record['pl_eqt']??null,'Distance (parsecs)'=>$record['sy_dist']??null],static fn($value):bool=>$value!==null&&$value!=='');
        $description=$title.' is a confirmed planetary record in the NASA Exoplanet Archive';
        return ['title'=>$title,'description'=>$description.'.','article_type'=>'Exoplanet','overview'=>$description.'. Measurements reflect the archive record at retrieval time.','facts'=>$facts,'categories'=>['Exoplanets','NASA Exoplanet Archive-backed articles']];
    }
}
