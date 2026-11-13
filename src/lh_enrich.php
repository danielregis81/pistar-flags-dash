<?php
/**
 * lh_enrich.php - Helpers para enriquecer o Last-Heard do dashboard
 * Pi-Star OFICIAL com:
 *   1) bandeira do pais (derivada do prefixo do indicativo, alocacao ITU)
 *   2) nome do operador (lookup em DMRIds.dat, com cache)
 *
 * Parte do projeto "pistar-flags-dash". Licenca GPL-3.0.
 *
 * Projetado para rodar bem ate em Raspberry Pi Zero W (ARMv6):
 *   - DMRIds.dat (~6-7MB) e lido UMA vez e os pares callsign->nome ficam
 *     num cache serializado em disco, reconstruido so quando o .dat muda
 *     (comparacao por mtime). Nos refreshes do dashboard le-se o cache.
 *   - Resolucao de pais e puramente em memoria.
 *   - Nenhuma chamada de rede.
 */

if (!defined('LH_ENRICH_LOADED')) {
    define('LH_ENRICH_LOADED', true);

    if (!defined('LH_DMRIDS_PATH'))  { define('LH_DMRIDS_PATH', '/usr/local/etc/DMRIds.dat'); }
    if (!defined('LH_NAMECACHE'))    { define('LH_NAMECACHE', sys_get_temp_dir() . '/lh_namecache.ser'); }
    if (!defined('LH_FLAGS_WEBDIR')) { define('LH_FLAGS_WEBDIR', '/images/flags'); }
    if (!defined('LH_FLAGS_FSDIR'))  { define('LH_FLAGS_FSDIR', $_SERVER['DOCUMENT_ROOT'] . '/images/flags'); }

    /**
     * Mapa de prefixos ITU -> ISO-3166-1 alpha-2 (minusculo).
     * As chaves sao os blocos de prefixos internacionais alocados pela ITU.
     * A resolucao testa prefixos de 2 caracteres e depois de 1 caractere.
     * Cobertura mundial (nao exaustiva em territorios menores, mas cobre a
     * grande maioria dos indicativos ativos em DMR/D-Star/YSF/etc.).
     */
    function lh_itu_prefix_map() {
        static $map = null;
        if ($map !== null) { return $map; }
        $map = array(
            // ---- America do Sul ----
            'PP'=>'br','PQ'=>'br','PR'=>'br','PS'=>'br','PT'=>'br','PU'=>'br','PV'=>'br','PW'=>'br','PX'=>'br','PY'=>'br',
            'ZV'=>'br','ZW'=>'br','ZX'=>'br','ZY'=>'br','ZZ'=>'br',
            'LU'=>'ar','LW'=>'ar','LO'=>'ar','LP'=>'ar','LQ'=>'ar','LR'=>'ar','LS'=>'ar','LT'=>'ar','LV'=>'ar','AY'=>'ar','AZ'=>'ar',
            'CA'=>'cl','CB'=>'cl','CC'=>'cl','CD'=>'cl','CE'=>'cl','XQ'=>'cl','XR'=>'cl',
            'CW'=>'uy','CX'=>'uy',
            'ZP'=>'py',
            'CP'=>'bo',
            'HC'=>'ec','HD'=>'ec',
            'OA'=>'pe','OB'=>'pe','OC'=>'pe',
            'HJ'=>'co','HK'=>'co',
            'YV'=>'ve','YW'=>'ve','YX'=>'ve','YY'=>'ve',
            '8R'=>'gy','PZ'=>'sr','FY'=>'gf',
            // ---- America Central / Caribe ----
            'XA'=>'mx','XB'=>'mx','XC'=>'mx','XD'=>'mx','XE'=>'mx','XF'=>'mx','XG'=>'mx','XH'=>'mx','XI'=>'mx',
            'TG'=>'gt','YS'=>'sv','HR'=>'hn','HT'=>'ht','YN'=>'ni','TI'=>'cr','HP'=>'pa','HO'=>'pa',
            'CO'=>'cu','CM'=>'cu','HI'=>'do','HH'=>'ht',
            'KP4'=>'pr','NP'=>'pr','WP'=>'pr','J3'=>'gd','J6'=>'lc','J7'=>'dm','J8'=>'vc',
            // ---- America do Norte ----
            'VA'=>'ca','VB'=>'ca','VC'=>'ca','VD'=>'ca','VE'=>'ca','VF'=>'ca','VG'=>'ca','VO'=>'ca','VY'=>'ca',
            'CF'=>'ca','CG'=>'ca','CH'=>'ca','CI'=>'ca','CJ'=>'ca','CK'=>'ca','CY'=>'ca','CZ'=>'ca',
            // ---- Europa ----
            'CT'=>'pt','CR'=>'pt','CS'=>'pt','CQ'=>'pt',
            'EA'=>'es','EB'=>'es','EC'=>'es','ED'=>'es','EE'=>'es','EF'=>'es','EG'=>'es','EH'=>'es',
            'DA'=>'de','DB'=>'de','DC'=>'de','DD'=>'de','DE'=>'de','DF'=>'de','DG'=>'de','DH'=>'de','DI'=>'de',
            'DJ'=>'de','DK'=>'de','DL'=>'de','DM'=>'de','DN'=>'de','DO'=>'de','DP'=>'de','DQ'=>'de','DR'=>'de',
            'IZ'=>'it','IK'=>'it','IW'=>'it','IU'=>'it','IN'=>'it','IR'=>'it','IQ'=>'it','IO'=>'it','IS'=>'it','IT'=>'it',
            'ON'=>'be','OO'=>'be','OP'=>'be','OQ'=>'be','OR'=>'be','OS'=>'be','OT'=>'be',
            'PA'=>'nl','PB'=>'nl','PC'=>'nl','PD'=>'nl','PE'=>'nl','PF'=>'nl','PG'=>'nl','PH'=>'nl','PI'=>'nl',
            'GI'=>'gb','GM'=>'gb','GW'=>'gb','2E'=>'gb',
            // dependencias da Coroa (entidades DXCC e bandeiras proprias)
            'GD'=>'im','MD'=>'im','2D'=>'im','GJ'=>'je','MJ'=>'je','2J'=>'je','GU'=>'gg','MU'=>'gg','2U'=>'gg',
            'EI'=>'ie','EJ'=>'ie',
            'OE'=>'at','HB'=>'ch','HE'=>'ch','LX'=>'lu','OZ'=>'dk','LA'=>'no','LB'=>'no','LC'=>'no',
            'SM'=>'se','SA'=>'se','SB'=>'se','SC'=>'se','SD'=>'se','SE'=>'se','SF'=>'se','SG'=>'se','SH'=>'se','SI'=>'se','SJ'=>'se','SK'=>'se','SL'=>'se','SM'=>'se',
            'OH'=>'fi','OF'=>'fi','OG'=>'fi','OI'=>'fi',
            'TF'=>'is','SP'=>'pl','SN'=>'pl','SO'=>'pl','SQ'=>'pl','SR'=>'pl','HF'=>'pl','3Z'=>'pl',
            'OK'=>'cz','OL'=>'cz','OM'=>'sk','HA'=>'hu','HG'=>'hu',
            'YO'=>'ro','YP'=>'ro','YQ'=>'ro','YR'=>'ro','LZ'=>'bg','SV'=>'gr','SW'=>'gr','SX'=>'gr','SY'=>'gr','SZ'=>'gr','J4'=>'gr',
            'YU'=>'rs','YT'=>'rs','9A'=>'hr','S5'=>'si','E7'=>'ba','Z3'=>'mk','ZA'=>'al','4O'=>'me','Z6'=>'xk',
            'LY'=>'lt','YL'=>'lv','ES'=>'ee','UR'=>'ua','UT'=>'ua','UU'=>'ua','UX'=>'ua','UY'=>'ua','EU'=>'by','EV'=>'by','EW'=>'by',
            'ER'=>'md','4L'=>'ge','EK'=>'am','4J'=>'az','4K'=>'az',
            'TK'=>'fr','F'=>'fr',
            '9H'=>'mt','5B'=>'cy','C4'=>'cy','ZB'=>'gi','T7'=>'sm','HV'=>'va','3A'=>'mc','OY'=>'fo',
            // ---- Russia / Asia ----
            'R'=>'ru','U'=>'ru',
            'JA'=>'jp','JB'=>'jp','JC'=>'jp','JD'=>'jp','JE'=>'jp','JF'=>'jp','JG'=>'jp','JH'=>'jp','JI'=>'jp','JJ'=>'jp',
            'JK'=>'jp','JL'=>'jp','JM'=>'jp','JN'=>'jp','JO'=>'jp','JP'=>'jp','JQ'=>'jp','JR'=>'jp','JS'=>'jp',
            'HL'=>'kr','DS'=>'kr','DT'=>'kr','6K'=>'kr','6L'=>'kr','6M'=>'kr','6N'=>'kr',
            'BY'=>'cn','BA'=>'cn','BG'=>'cn','BD'=>'cn','BH'=>'cn','BI'=>'cn','BT'=>'cn',
            'BV'=>'tw','BM'=>'tw','BN'=>'tw','BO'=>'tw','BP'=>'tw','BQ'=>'tw','BU'=>'tw','BX'=>'tw',
            'VR'=>'hk','XX'=>'mo',
            'VU'=>'in','AT'=>'in','8T'=>'in','8U'=>'in',
            'HS'=>'th','E2'=>'th','XW'=>'la','XU'=>'kh','XV'=>'vn','3W'=>'vn',
            '9M'=>'my','9W'=>'my','9V'=>'sg','YB'=>'id','YC'=>'id','YD'=>'id','YE'=>'id','YF'=>'id','YG'=>'id','YH'=>'id',
            'DU'=>'ph','DV'=>'ph','DW'=>'ph','DX'=>'ph','DY'=>'ph','DZ'=>'ph',
            'S2'=>'bd','4S'=>'lk','AP'=>'pk','A4'=>'om','A6'=>'ae','A7'=>'qa','A9'=>'bh','9K'=>'kw',
            'HZ'=>'sa','7Z'=>'sa','YI'=>'iq','YK'=>'sy','OD'=>'lb','JY'=>'jo','4X'=>'il','4Z'=>'il','EP'=>'ir','EQ'=>'ir',
            'EX'=>'kg','EY'=>'tj','EZ'=>'tm','UK'=>'uz','UN'=>'kz','UO'=>'kz','UP'=>'kz','UQ'=>'kz',
            'XZ'=>'mm','9N'=>'np','A5'=>'bt',
            // ---- Oceania ----
            'VK'=>'au','AX'=>'au','ZL'=>'nz','ZM'=>'nz','E5'=>'ck','3D'=>'fj','T2'=>'tv','T3'=>'ki','5W'=>'ws','A3'=>'to',
            'P2'=>'pg','H4'=>'sb','YJ'=>'vu','FK'=>'nc','FO'=>'pf','KH6'=>'us','KH'=>'us',
            // ---- Africa ----
            'ZS'=>'za','ZR'=>'za','ZT'=>'za','ZU'=>'za','5R'=>'mg','5H'=>'tz','5X'=>'ug','5Y'=>'ke','5Z'=>'ke',
            'CN'=>'ma','7X'=>'dz','3V'=>'tn','SU'=>'eg','5A'=>'ly','ST'=>'sd',
            'TY'=>'bj','TU'=>'ci','TT'=>'td','TR'=>'ga','TN'=>'cg','TL'=>'cf','TJ'=>'cm',
            '5N'=>'ng','5T'=>'mr','5U'=>'ne','6W'=>'sn','C5'=>'gm','9G'=>'gh','9J'=>'zm','9L'=>'sl',
            'D2'=>'ao','C9'=>'mz','7Q'=>'mw','V5'=>'na','A2'=>'bw','3DA'=>'sz','7P'=>'ls','Z2'=>'zw','3B'=>'mu','S7'=>'sc',
            'TZ'=>'ml','XT'=>'bf','6V'=>'sn','J5'=>'gw','D4'=>'cv','3C'=>'gq','TJ'=>'cm','9Q'=>'cd','9U'=>'bi','9X'=>'rw',
            'ET'=>'et','E3'=>'er','T5'=>'so','J2'=>'dj',
            // ---- Complementos (2026-11-13) ----
            'TA'=>'tr','TB'=>'tr','TC'=>'tr','YM'=>'tr',
            'YA'=>'af','T6'=>'af','C3'=>'ad','HB0'=>'li','E4'=>'ps','OX'=>'gl','XP'=>'gl','JW'=>'sj',
            'JT'=>'mn','JU'=>'mn','JV'=>'mn','7O'=>'ye','8Q'=>'mv','4W'=>'tl','V8'=>'bn','P5'=>'kp',
            'EL'=>'lr','5L'=>'lr','5M'=>'lr','6Z'=>'lr','A8'=>'lr','D5'=>'lr',
            '5V'=>'tg','3X'=>'gn','S0'=>'eh','S9'=>'st','D6'=>'km','Z8'=>'ss',
            '6Y'=>'jm','8P'=>'bb','C6'=>'bs','V3'=>'bz','9Y'=>'tt','9Z'=>'tt','V4'=>'kn','V2'=>'ag',
            'P4'=>'aw','PJ2'=>'cw','PJ4'=>'bq','PJ7'=>'sx','ZF'=>'ky','VP9'=>'bm','VP5'=>'tc','VP8'=>'fk','VP6'=>'pn','ZD7'=>'sh',
            'KP2'=>'vi','NP2'=>'vi','WP2'=>'vi','KH2'=>'gu','KH0'=>'mp','KH8'=>'as',
            'V6'=>'fm','V7'=>'mh','T8'=>'pw','C2'=>'nr','E6'=>'nu','ZK3'=>'tk',
            'FR'=>'re','FG'=>'gp','FM'=>'mq','FS'=>'mf','FJ'=>'bl','FP'=>'pm','FH'=>'yt','FW'=>'wf',
            'OH0'=>'ax','VQ9'=>'io',
        );
        return $map;
    }

    function lh_itu_prefix_map1() {
        static $map1 = null;
        if ($map1 !== null) { return $map1; }
        $map1 = array(
            'K'=>'us','N'=>'us','W'=>'us','A'=>'us',  // EUA (A so se nao casar A2/A3/... acima)
            'G'=>'gb','M'=>'gb',
            'F'=>'fr',
            'I'=>'it',
            'R'=>'ru','U'=>'ru',
            'G'=>'gb',
        );
        return $map1;
    }

    /**
     * Resolve o indicativo para um ISO-3166-1 alpha-2 (minusculo) ou ''.
     */
    function lh_callsign_to_iso($callsign) {
        $cs = strtoupper(trim((string)$callsign));
        if ($cs === '') { return ''; }
        $cs = preg_replace('#[-/].*$#', '', $cs);           // remove sufixo /P, -7, etc
        if ($cs === '' || !preg_match('/^[A-Z0-9]/', $cs)) { return ''; }

        $map  = lh_itu_prefix_map();
        $map1 = lh_itu_prefix_map1();

        // tentar prefixo de 3 chars (ex: KP4, KH6, 3DA), depois 2, depois 1
        $p3 = substr($cs, 0, 3);
        if (isset($map[$p3])) { return $map[$p3]; }
        $p2 = substr($cs, 0, 2);
        if (isset($map[$p2])) { return $map[$p2]; }
        $p1 = substr($cs, 0, 1);
        if (isset($map[$p1]))  { return $map[$p1]; }
        if (isset($map1[$p1])) { return $map1[$p1]; }
        return '';
    }

    /**
     * Converte ISO-3166-1 alpha-2 (minusculo) -> alpha-3 (maiusculo).
     * Cobre todos os paises presentes no mapa de prefixos ITU acima.
     * Retorna '' se nao mapeado.
     */
    function lh_iso2_to_iso3($iso2) {
        static $m = null;
        if ($m === null) {
            $m = array(
                'br'=>'BRA','ar'=>'ARG','cl'=>'CHL','uy'=>'URY','py'=>'PRY','bo'=>'BOL',
                'ec'=>'ECU','pe'=>'PER','co'=>'COL','ve'=>'VEN','gy'=>'GUY','sr'=>'SUR','gf'=>'GUF',
                'mx'=>'MEX','gt'=>'GTM','sv'=>'SLV','hn'=>'HND','ht'=>'HTI','ni'=>'NIC','cr'=>'CRI',
                'pa'=>'PAN','cu'=>'CUB','do'=>'DOM','pr'=>'PRI','gd'=>'GRD','lc'=>'LCA','dm'=>'DMA','vc'=>'VCT',
                'ca'=>'CAN','us'=>'USA',
                'pt'=>'PRT','es'=>'ESP','de'=>'DEU','it'=>'ITA','be'=>'BEL','nl'=>'NLD','gb'=>'GBR',
                'ie'=>'IRL','at'=>'AUT','ch'=>'CHE','lu'=>'LUX','dk'=>'DNK','no'=>'NOR','se'=>'SWE',
                'fi'=>'FIN','is'=>'ISL','pl'=>'POL','cz'=>'CZE','sk'=>'SVK','hu'=>'HUN','ro'=>'ROU',
                'bg'=>'BGR','gr'=>'GRC','rs'=>'SRB','hr'=>'HRV','si'=>'SVN','ba'=>'BIH','mk'=>'MKD',
                'al'=>'ALB','me'=>'MNE','xk'=>'XKX','lt'=>'LTU','lv'=>'LVA','ee'=>'EST','ua'=>'UKR',
                'by'=>'BLR','md'=>'MDA','ge'=>'GEO','am'=>'ARM','az'=>'AZE','fr'=>'FRA','mt'=>'MLT',
                'cy'=>'CYP','sm'=>'SMR','va'=>'VAT','mc'=>'MCO','fo'=>'FRO','ru'=>'RUS',
                'jp'=>'JPN','kr'=>'KOR','cn'=>'CHN','tw'=>'TWN','hk'=>'HKG','mo'=>'MAC','in'=>'IND',
                'th'=>'THA','la'=>'LAO','kh'=>'KHM','vn'=>'VNM','my'=>'MYS','sg'=>'SGP','id'=>'IDN',
                'ph'=>'PHL','bd'=>'BGD','lk'=>'LKA','pk'=>'PAK','om'=>'OMN','ae'=>'ARE','qa'=>'QAT',
                'bh'=>'BHR','kw'=>'KWT','sa'=>'SAU','iq'=>'IRQ','sy'=>'SYR','lb'=>'LBN','jo'=>'JOR',
                'il'=>'ISR','ir'=>'IRN','kg'=>'KGZ','tj'=>'TJK','tm'=>'TKM','uz'=>'UZB','kz'=>'KAZ',
                'mm'=>'MMR','np'=>'NPL','bt'=>'BTN',
                'au'=>'AUS','nz'=>'NZL','ck'=>'COK','fj'=>'FJI','tv'=>'TUV','ki'=>'KIR','ws'=>'WSM',
                'to'=>'TON','pg'=>'PNG','sb'=>'SLB','vu'=>'VUT','nc'=>'NCL','pf'=>'PYF',
                'za'=>'ZAF','mg'=>'MDG','tz'=>'TZA','ug'=>'UGA','ke'=>'KEN','ma'=>'MAR','dz'=>'DZA',
                'tn'=>'TUN','eg'=>'EGY','ly'=>'LBY','sd'=>'SDN','bj'=>'BEN','ci'=>'CIV','td'=>'TCD',
                'ga'=>'GAB','cg'=>'COG','cf'=>'CAF','cm'=>'CMR','ng'=>'NGA','mr'=>'MRT','ne'=>'NER',
                'sn'=>'SEN','gm'=>'GMB','gh'=>'GHA','zm'=>'ZMB','sl'=>'SLE','ao'=>'AGO','mz'=>'MOZ',
                'mw'=>'MWI','na'=>'NAM','bw'=>'BWA','sz'=>'SWZ','ls'=>'LSO','zw'=>'ZWE','mu'=>'MUS',
                'sc'=>'SYC','ml'=>'MLI','bf'=>'BFA','gw'=>'GNB','cv'=>'CPV','gq'=>'GNQ','cd'=>'COD',
                'bi'=>'BDI','rw'=>'RWA','et'=>'ETH','er'=>'ERI','so'=>'SOM','dj'=>'DJI',
                'tr'=>'TUR','af'=>'AFG','ad'=>'AND','li'=>'LIE','ps'=>'PSE','gl'=>'GRL','sj'=>'SJM',
                'mn'=>'MNG','ye'=>'YEM','mv'=>'MDV','tl'=>'TLS','bn'=>'BRN','kp'=>'PRK','lr'=>'LBR',
                'tg'=>'TGO','gn'=>'GIN','eh'=>'ESH','st'=>'STP','km'=>'COM','ss'=>'SSD',
                'jm'=>'JAM','bb'=>'BRB','bs'=>'BHS','bz'=>'BLZ','tt'=>'TTO','kn'=>'KNA','ag'=>'ATG',
                'aw'=>'ABW','cw'=>'CUW','bq'=>'BES','sx'=>'SXM','ky'=>'CYM','bm'=>'BMU','tc'=>'TCA',
                'fk'=>'FLK','pn'=>'PCN','sh'=>'SHN','vi'=>'VIR','gu'=>'GUM','mp'=>'MNP','as'=>'ASM',
                'fm'=>'FSM','mh'=>'MHL','pw'=>'PLW','nr'=>'NRU','nu'=>'NIU','tk'=>'TKL',
                're'=>'REU','gp'=>'GLP','mq'=>'MTQ','mf'=>'MAF','bl'=>'BLM','pm'=>'SPM','yt'=>'MYT','wf'=>'WLF',
                'im'=>'IMN','je'=>'JEY','gg'=>'GGY','gi'=>'GIB','ax'=>'ALA','io'=>'IOT',
            );
        }
        $k = strtolower((string)$iso2);
        return isset($m[$k]) ? $m[$k] : '';
    }

    /**
     * Codigo ISO-3 (alpha-3) do pais do indicativo, ou '' se nao reconhecido.
     * Usado na coluna callsign no lugar do antigo link "(GPS)".
     */
    function lh_iso3($callsign) {
        $iso2 = lh_callsign_to_iso($callsign);
        if ($iso2 === '') { return ''; }
        return lh_iso2_to_iso3($iso2);
    }

    /**
     * Nome do pais por extenso para o indicativo, no idioma do dashboard.
     * $pistarLanguage vem de /config/language.php (ex: 'english_uk',
     * 'portuguese_br'). Suporta EN e PT; demais idiomas caem em EN.
     * Retorna '' se o pais nao for reconhecido.
     */
    function lh_country_name($callsign, $pistarLanguage = 'english_uk') {
        $iso2 = lh_callsign_to_iso($callsign);
        if ($iso2 === '') { return ''; }

        // seleciona o conjunto de nomes conforme o idioma do dashboard
        $isPt = (stripos((string)$pistarLanguage, 'portuguese') === 0)
             || (stripos((string)$pistarLanguage, 'portugues') === 0);

        static $en = null; static $pt = null;
        if ($en === null) {
            $en = array(
                'br'=>'Brazil','ar'=>'Argentina','cl'=>'Chile','uy'=>'Uruguay','py'=>'Paraguay','bo'=>'Bolivia',
                'ec'=>'Ecuador','pe'=>'Peru','co'=>'Colombia','ve'=>'Venezuela','gy'=>'Guyana','sr'=>'Suriname','gf'=>'French Guiana',
                'mx'=>'Mexico','gt'=>'Guatemala','sv'=>'El Salvador','hn'=>'Honduras','ht'=>'Haiti','ni'=>'Nicaragua','cr'=>'Costa Rica',
                'pa'=>'Panama','cu'=>'Cuba','do'=>'Dominican Republic','pr'=>'Puerto Rico','gd'=>'Grenada','lc'=>'Saint Lucia','dm'=>'Dominica','vc'=>'Saint Vincent',
                'ca'=>'Canada','us'=>'United States',
                'pt'=>'Portugal','es'=>'Spain','de'=>'Germany','it'=>'Italy','be'=>'Belgium','nl'=>'Netherlands','gb'=>'United Kingdom',
                'ie'=>'Ireland','at'=>'Austria','ch'=>'Switzerland','lu'=>'Luxembourg','dk'=>'Denmark','no'=>'Norway','se'=>'Sweden',
                'fi'=>'Finland','is'=>'Iceland','pl'=>'Poland','cz'=>'Czechia','sk'=>'Slovakia','hu'=>'Hungary','ro'=>'Romania',
                'bg'=>'Bulgaria','gr'=>'Greece','rs'=>'Serbia','hr'=>'Croatia','si'=>'Slovenia','ba'=>'Bosnia and Herzegovina','mk'=>'North Macedonia',
                'al'=>'Albania','me'=>'Montenegro','xk'=>'Kosovo','lt'=>'Lithuania','lv'=>'Latvia','ee'=>'Estonia','ua'=>'Ukraine',
                'by'=>'Belarus','md'=>'Moldova','ge'=>'Georgia','am'=>'Armenia','az'=>'Azerbaijan','fr'=>'France','mt'=>'Malta',
                'cy'=>'Cyprus','sm'=>'San Marino','va'=>'Vatican City','mc'=>'Monaco','fo'=>'Faroe Islands','ru'=>'Russia',
                'jp'=>'Japan','kr'=>'South Korea','cn'=>'China','tw'=>'Taiwan','hk'=>'Hong Kong','mo'=>'Macau','in'=>'India',
                'th'=>'Thailand','la'=>'Laos','kh'=>'Cambodia','vn'=>'Vietnam','my'=>'Malaysia','sg'=>'Singapore','id'=>'Indonesia',
                'ph'=>'Philippines','bd'=>'Bangladesh','lk'=>'Sri Lanka','pk'=>'Pakistan','om'=>'Oman','ae'=>'United Arab Emirates','qa'=>'Qatar',
                'bh'=>'Bahrain','kw'=>'Kuwait','sa'=>'Saudi Arabia','iq'=>'Iraq','sy'=>'Syria','lb'=>'Lebanon','jo'=>'Jordan',
                'il'=>'Israel','ir'=>'Iran','kg'=>'Kyrgyzstan','tj'=>'Tajikistan','tm'=>'Turkmenistan','uz'=>'Uzbekistan','kz'=>'Kazakhstan',
                'mm'=>'Myanmar','np'=>'Nepal','bt'=>'Bhutan',
                'au'=>'Australia','nz'=>'New Zealand','ck'=>'Cook Islands','fj'=>'Fiji','tv'=>'Tuvalu','ki'=>'Kiribati','ws'=>'Samoa',
                'to'=>'Tonga','pg'=>'Papua New Guinea','sb'=>'Solomon Islands','vu'=>'Vanuatu','nc'=>'New Caledonia','pf'=>'French Polynesia',
                'za'=>'South Africa','mg'=>'Madagascar','tz'=>'Tanzania','ug'=>'Uganda','ke'=>'Kenya','ma'=>'Morocco','dz'=>'Algeria',
                'tn'=>'Tunisia','eg'=>'Egypt','ly'=>'Libya','sd'=>'Sudan','bj'=>'Benin','ci'=>'Ivory Coast','td'=>'Chad',
                'ga'=>'Gabon','cg'=>'Congo','cf'=>'Central African Republic','cm'=>'Cameroon','ng'=>'Nigeria','mr'=>'Mauritania','ne'=>'Niger',
                'sn'=>'Senegal','gm'=>'Gambia','gh'=>'Ghana','zm'=>'Zambia','sl'=>'Sierra Leone','ao'=>'Angola','mz'=>'Mozambique',
                'mw'=>'Malawi','na'=>'Namibia','bw'=>'Botswana','sz'=>'Eswatini','ls'=>'Lesotho','zw'=>'Zimbabwe','mu'=>'Mauritius',
                'sc'=>'Seychelles','ml'=>'Mali','bf'=>'Burkina Faso','gw'=>'Guinea-Bissau','cv'=>'Cape Verde','gq'=>'Equatorial Guinea','cd'=>'DR Congo',
                'bi'=>'Burundi','rw'=>'Rwanda','et'=>'Ethiopia','er'=>'Eritrea','so'=>'Somalia','dj'=>'Djibouti',
                'tr'=>'Turkey','af'=>'Afghanistan','ad'=>'Andorra','li'=>'Liechtenstein','ps'=>'Palestine','gl'=>'Greenland','sj'=>'Svalbard',
                'mn'=>'Mongolia','ye'=>'Yemen','mv'=>'Maldives','tl'=>'Timor-Leste','bn'=>'Brunei','kp'=>'North Korea','lr'=>'Liberia',
                'tg'=>'Togo','gn'=>'Guinea','eh'=>'Western Sahara','st'=>'Sao Tome and Principe','km'=>'Comoros','ss'=>'South Sudan',
                'jm'=>'Jamaica','bb'=>'Barbados','bs'=>'Bahamas','bz'=>'Belize','tt'=>'Trinidad and Tobago','kn'=>'Saint Kitts and Nevis','ag'=>'Antigua and Barbuda',
                'aw'=>'Aruba','cw'=>'Curacao','bq'=>'Caribbean Netherlands','sx'=>'Sint Maarten','ky'=>'Cayman Islands','bm'=>'Bermuda','tc'=>'Turks and Caicos',
                'fk'=>'Falkland Islands','pn'=>'Pitcairn Islands','sh'=>'Saint Helena','vi'=>'US Virgin Islands','gu'=>'Guam','mp'=>'Northern Mariana Islands','as'=>'American Samoa',
                'fm'=>'Micronesia','mh'=>'Marshall Islands','pw'=>'Palau','nr'=>'Nauru','nu'=>'Niue','tk'=>'Tokelau',
                're'=>'Reunion','gp'=>'Guadeloupe','mq'=>'Martinique','mf'=>'Saint Martin','bl'=>'Saint Barthelemy','pm'=>'Saint Pierre and Miquelon','yt'=>'Mayotte','wf'=>'Wallis and Futuna',
                'im'=>'Isle of Man','je'=>'Jersey','gg'=>'Guernsey','gi'=>'Gibraltar','ax'=>'Aland Islands','io'=>'British Indian Ocean Territory',
            );
        }
        if ($pt === null) {
            $pt = array(
                'br'=>'Brasil','ar'=>'Argentina','cl'=>'Chile','uy'=>'Uruguai','py'=>'Paraguai','bo'=>'Bolivia',
                'ec'=>'Equador','pe'=>'Peru','co'=>'Colombia','ve'=>'Venezuela','gy'=>'Guiana','sr'=>'Suriname','gf'=>'Guiana Francesa',
                'mx'=>'Mexico','gt'=>'Guatemala','sv'=>'El Salvador','hn'=>'Honduras','ht'=>'Haiti','ni'=>'Nicaragua','cr'=>'Costa Rica',
                'pa'=>'Panama','cu'=>'Cuba','do'=>'Republica Dominicana','pr'=>'Porto Rico','gd'=>'Granada','lc'=>'Santa Lucia','dm'=>'Dominica','vc'=>'Sao Vicente e Granadinas',
                'ca'=>'Canada','us'=>'Estados Unidos',
                'pt'=>'Portugal','es'=>'Espanha','de'=>'Alemanha','it'=>'Italia','be'=>'Belgica','nl'=>'Paises Baixos','gb'=>'Reino Unido',
                'ie'=>'Irlanda','at'=>'Austria','ch'=>'Suica','lu'=>'Luxemburgo','dk'=>'Dinamarca','no'=>'Noruega','se'=>'Suecia',
                'fi'=>'Finlandia','is'=>'Islandia','pl'=>'Polonia','cz'=>'Chequia','sk'=>'Eslovaquia','hu'=>'Hungria','ro'=>'Romenia',
                'bg'=>'Bulgaria','gr'=>'Grecia','rs'=>'Servia','hr'=>'Croacia','si'=>'Eslovenia','ba'=>'Bosnia e Herzegovina','mk'=>'Macedonia do Norte',
                'al'=>'Albania','me'=>'Montenegro','xk'=>'Kosovo','lt'=>'Lituania','lv'=>'Letonia','ee'=>'Estonia','ua'=>'Ucrania',
                'by'=>'Bielorrussia','md'=>'Moldova','ge'=>'Georgia','am'=>'Armenia','az'=>'Azerbaijao','fr'=>'Franca','mt'=>'Malta',
                'cy'=>'Chipre','sm'=>'San Marino','va'=>'Vaticano','mc'=>'Monaco','fo'=>'Ilhas Faroe','ru'=>'Russia',
                'jp'=>'Japao','kr'=>'Coreia do Sul','cn'=>'China','tw'=>'Taiwan','hk'=>'Hong Kong','mo'=>'Macau','in'=>'India',
                'th'=>'Tailandia','la'=>'Laos','kh'=>'Camboja','vn'=>'Vietna','my'=>'Malasia','sg'=>'Singapura','id'=>'Indonesia',
                'ph'=>'Filipinas','bd'=>'Bangladesh','lk'=>'Sri Lanka','pk'=>'Paquistao','om'=>'Oma','ae'=>'Emirados Arabes Unidos','qa'=>'Catar',
                'bh'=>'Bahrein','kw'=>'Kuwait','sa'=>'Arabia Saudita','iq'=>'Iraque','sy'=>'Siria','lb'=>'Libano','jo'=>'Jordania',
                'il'=>'Israel','ir'=>'Ira','kg'=>'Quirguistao','tj'=>'Tajiquistao','tm'=>'Turcomenistao','uz'=>'Uzbequistao','kz'=>'Cazaquistao',
                'mm'=>'Mianmar','np'=>'Nepal','bt'=>'Butao',
                'au'=>'Australia','nz'=>'Nova Zelandia','ck'=>'Ilhas Cook','fj'=>'Fiji','tv'=>'Tuvalu','ki'=>'Kiribati','ws'=>'Samoa',
                'to'=>'Tonga','pg'=>'Papua-Nova Guine','sb'=>'Ilhas Salomao','vu'=>'Vanuatu','nc'=>'Nova Caledonia','pf'=>'Polinesia Francesa',
                'za'=>'Africa do Sul','mg'=>'Madagascar','tz'=>'Tanzania','ug'=>'Uganda','ke'=>'Quenia','ma'=>'Marrocos','dz'=>'Argelia',
                'tn'=>'Tunisia','eg'=>'Egito','ly'=>'Libia','sd'=>'Sudao','bj'=>'Benin','ci'=>'Costa do Marfim','td'=>'Chade',
                'ga'=>'Gabao','cg'=>'Congo','cf'=>'Republica Centro-Africana','cm'=>'Camaroes','ng'=>'Nigeria','mr'=>'Mauritania','ne'=>'Niger',
                'sn'=>'Senegal','gm'=>'Gambia','gh'=>'Gana','zm'=>'Zambia','sl'=>'Serra Leoa','ao'=>'Angola','mz'=>'Mocambique',
                'mw'=>'Malawi','na'=>'Namibia','bw'=>'Botsuana','sz'=>'Essuatini','ls'=>'Lesoto','zw'=>'Zimbabue','mu'=>'Mauricio',
                'sc'=>'Seicheles','ml'=>'Mali','bf'=>'Burquina Faso','gw'=>'Guine-Bissau','cv'=>'Cabo Verde','gq'=>'Guine Equatorial','cd'=>'Republica Democratica do Congo',
                'bi'=>'Burundi','rw'=>'Ruanda','et'=>'Etiopia','er'=>'Eritreia','so'=>'Somalia','dj'=>'Djibuti',
                'tr'=>'Turquia','af'=>'Afeganistao','ad'=>'Andorra','li'=>'Liechtenstein','ps'=>'Palestina','gl'=>'Groenlandia','sj'=>'Svalbard',
                'mn'=>'Mongolia','ye'=>'Iemen','mv'=>'Maldivas','tl'=>'Timor-Leste','bn'=>'Brunei','kp'=>'Coreia do Norte','lr'=>'Liberia',
                'tg'=>'Togo','gn'=>'Guine','eh'=>'Saara Ocidental','st'=>'Sao Tome e Principe','km'=>'Comores','ss'=>'Sudao do Sul',
                'jm'=>'Jamaica','bb'=>'Barbados','bs'=>'Bahamas','bz'=>'Belize','tt'=>'Trinidad e Tobago','kn'=>'Sao Cristovao e Neves','ag'=>'Antigua e Barbuda',
                'aw'=>'Aruba','cw'=>'Curacao','bq'=>'Caribe Neerlandes','sx'=>'Sint Maarten','ky'=>'Ilhas Cayman','bm'=>'Bermudas','tc'=>'Turcas e Caicos',
                'fk'=>'Ilhas Malvinas','pn'=>'Ilhas Pitcairn','sh'=>'Santa Helena','vi'=>'Ilhas Virgens Americanas','gu'=>'Guam','mp'=>'Ilhas Marianas do Norte','as'=>'Samoa Americana',
                'fm'=>'Micronesia','mh'=>'Ilhas Marshall','pw'=>'Palau','nr'=>'Nauru','nu'=>'Niue','tk'=>'Tokelau',
                're'=>'Reuniao','gp'=>'Guadalupe','mq'=>'Martinica','mf'=>'Sao Martinho','bl'=>'Sao Bartolomeu','pm'=>'Sao Pedro e Miquelao','yt'=>'Mayotte','wf'=>'Wallis e Futuna',
                'im'=>'Ilha de Man','je'=>'Jersey','gg'=>'Guernsey','gi'=>'Gibraltar','ax'=>'Ilhas Aland','io'=>'Territorio Britanico do Oceano Indico',
            );
        }

        $set = $isPt ? $pt : $en;
        return isset($set[$iso2]) ? $set[$iso2] : (isset($en[$iso2]) ? $en[$iso2] : '');
    }

    /**
     * HTML de <img> da bandeira para o indicativo, ou '' se o pais nao for
     * reconhecido OU o arquivo SVG nao existir em disco.
     */
    function lh_flag_img($callsign) {
        $iso = lh_callsign_to_iso($callsign);
        if ($iso === '') { return ''; }
        $file = LH_FLAGS_FSDIR . '/' . $iso . '.svg';
        if (!is_file($file)) { return ''; }
        $isoHtml = htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
        $url = LH_FLAGS_WEBDIR . '/' . $isoHtml . '.svg';
        return '<img src="' . $url . '" alt="' . $isoHtml . '" title="' . $isoHtml . '" '
             . 'style="width:1.4em;height:1em;object-fit:cover;vertical-align:middle;'
             . 'margin-right:5px;border:1px solid rgba(0,0,0,0.25);box-sizing:border-box;" />';
    }

    /**
     * Mapa callsign(maiusculo)->nome a partir do DMRIds.dat, com cache em
     * disco invalidado por mtime. Degrada para array vazio em qualquer falha.
     */
    function lh_name_map() {
        static $cache = null;
        if ($cache !== null) { return $cache; }

        $datPath = LH_DMRIDS_PATH;
        if (!is_readable($datPath)) { $cache = array(); return $cache; }
        $datMtime = @filemtime($datPath);

        $cacheFile = LH_NAMECACHE;
        if (is_readable($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $obj = @unserialize($raw);
                if (is_array($obj) && isset($obj['mtime'], $obj['map']) && $obj['mtime'] === $datMtime) {
                    $cache = $obj['map'];
                    return $cache;
                }
            }
        }

        $map = array();
        $fh = @fopen($datPath, 'r');
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                $parts = explode("\t", rtrim($line, "\r\n"));  // ID<TAB>CALLSIGN<TAB>NOME
                if (count($parts) >= 3) {
                    $csKey = strtoupper(trim($parts[1]));
                    if ($csKey !== '' && !isset($map[$csKey])) {
                        $map[$csKey] = trim($parts[2]);
                    }
                }
            }
            fclose($fh);
        }
        @file_put_contents($cacheFile, serialize(array('mtime'=>$datMtime, 'map'=>$map)), LOCK_EX);

        $cache = $map;
        return $cache;
    }

    /**
     * Nome do operador (HTML-escapado) para um indicativo, ou ''.
     */
    function lh_operator_name($callsign) {
        $cs = strtoupper(trim((string)$callsign));
        $cs = preg_replace('#[-/].*$#', '', $cs);
        if ($cs === '') { return ''; }
        $map = lh_name_map();
        if (isset($map[$cs]) && $map[$cs] !== '') {
            return htmlspecialchars($map[$cs], ENT_QUOTES, 'UTF-8');
        }
        return '';
    }
}
