<?php

/**
 * Created by PhpStorm.
 * User: don
 * Date: 3/8/2020
 * Time: 5:13 PM
 *
 * @param $pathregex
 * @param $path
 *
 * @return array
 */

//require_once "WordCount.class.php";

define( 'TEIPATH', dirname(__DIR__) . '/tei/outputs' );
define( 'PAGEPATH', TEIPATH . '/page' );
define( 'ENTRYPATH', TEIPATH . '/entry' );

// 0 entire 1 prefix 2 words 3
define( 'HYPHEN_REGEX', "(\pL+)-(\s+(\pL+)");
define( 'HYPHEN_SPACE_REGEX',  "((?:\w+\W+){0,5})((\p{L}+)-\s+(\p{L}+))(?:\W*\w+){0,5})");

function edition_page_path($edition) {
	return build_path(PAGEPATH, $edition);
}
function edition_entry_path($edition) {
	return build_path(ENTRYPATH, $edition);
}
//function edition_page_glob($edition) {
//	return glob(build_path(edition_page_path($edition), "*/*.xml"));
//}
//function edition_entry_glob($edition) {
//	return glob(build_path(edition_entry_path($edition), "*/*.xml"));
//}

function tei_page_path($edition, $volume, $pgnum) {
	$pgseq = PgNumToPgSeq($edition, $volume, $pgnum);
	return sprintf("%s/%s/volume%02d/%s-%02d-%04d-%04d.xml",
				PAGEPATH, $edition, $volume, $edition, $volume, $pgseq, $pgnum);
}
function decode_page_path($path) {
	$edition = RegexMatch("/(eb\d+)/", "", $path);
	$code = RegexMatch("/(.\d\d)/", "", $path);
	$index = RegexMatch("(\d+)\.xml$", "um", $path);
	$ret = [$edition, $code, $index];
	return $ret;
}
function PgNumToPgSeq($edition, $volume, $pgnum) {
	global $ebdb;
	$sql = "SELECT src_seq FROM src_pagemaps
				WHERE edition = ? AND volume = ? AND pgnum = ?";
	$args = [&$edition, &$volume, &$pgnum];
	return $ebdb->SqlOneValuePS($sql, $args);
}

//function encode_page_path($edition, $group = "*", $index = "*") {
//	return "tei/page/{$edition}/{$group}/3-page-tei/{$index}.xml";
//}

//function encode_entry_path($edition, $code = "*") {
//	return "tei/entry/{$edition}/{$code}/4-entry-tei/*.xml";
//}

function PageMap($edition, $volume) {
	global $ebdb;
	$sql = "SELECT pgnum, src_seq, resource
			FROM src_pagemaps
			WHERE edition = ?
				aND volume = ?";
	$args = [&$edition, &$volume];
	$rows = $ebdb->SqlRowsPS($sql, $args);
	$map = [];
	foreach($rows as $row) {
		$pgnum = (int) $row["pgnum"];
		$pgseq = (int) $row["src_seq"];
		$resource = $row["resource"];
		$map[$pgnum] = [ "pgseq" => $pgseq, "resource" => $resource ];
	}
	return $map;
}


abstract class TEI {
	private $path;
	private $xmltext;
	private $textslices;
	private $contentslices;
	private $header;
	private $facs;
	private $original_content;
	private $trailer;
	protected $edition;
	protected $volume;
	protected $pgnum;
	protected $pgseq;
	protected $pgindex;
	private $phase;

	const TAGREGEX = '(<.*?>)';

	public function __construct($path, $phase, $edition) {
		$this->path = $path;
		$this->phase = $phase;
		$this->edition = $edition;
		$this->xmltext = file_get_contents($path);
		$this->init_slices();
//		$this->init_volume_pgnum();
	}

	public function PgNum() {
		return $this->pgnum;
	}

	public function PgSeq() {
		return $this->pgseq;
	}

	public function PgIndex() {
		return $this->pgindex;
	}

	public function TeiName() {
		return explode(".", basename($this->Path()))[0];
	}

	public function Path() {
		return $this->path;
	}

	public function Phase() {
		return $this->phase;
	}

	public function ImageUrl($pgnum) {
		global $context;
//		return $context->ImageUrl($this->Edition(), $this->Volume(), $this->Pgnum());
		return $context->ImageUrl($this->Edition(), $this->Volume(), $pgnum);
	}

	public function Edition() {
		return $this->edition;
	}

	public function Volume() {
		return $this->volume;
	}

	private function init_slices() {
//		$spl = RegexMatchFields("(^.*<body>)(.*)(</body>.*$)", "us", $this->xmltext);
		$spl = RegexMatchFields("(^.*<body.*?>).*?(<div\s+facs.*?>)(.*)(<\/body>.*$)", "us", $this->xmltext);
		if(count($spl) < 4) {
			trace("Faulty xml file - count: " . count($spl) . $this->Path());
			trace("<{$spl[1]}> <{$spl[2]}> <{$spl[3]}> <{$spl[5]}>");
			exit;
		}
		$this->header = $spl[1];
		$this->facs = $spl[2];
		$this->original_content = $spl[3];
		$this->trailer = $spl[4];
		$this->contentslices = RegexSplitWithSplitter( self::TAGREGEX, "us", $this->original_content );
		foreach($this->contentslices as $key => $slice) {
			if ( left( $slice, 1 ) == "<" ) {
				continue;
			}
			$this->textslices[$key] = $slice;
		}
	}



	public function FormattedXML() {
		$load_xml = simplexml_load_string($this->xmltext);
		$dom = new DOMDocument('1.0');
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML($load_xml->asXML());
		$formatxml = new SimpleXMLElement($dom->saveXML());
		return $formatxml->saveXML();
	}

	public function HyphenatedLineBreaks() {
		$awords = [];
        $regex = HYPHEN_REGEX;
//		$regex = "(\pL+)-\s+(\pL+)";
		$flags = "us";
		$hwords = $this->RegexMatchFieldsArray($regex, $flags);
		foreach($hwords as $hword) {
			if( RegexMatch("\p{Greek}", "ui", $hword)) {
				continue;
			}
			if(isset($awords[$hword])) {
				$awords[$hword]++;
			}
			else {
				$awords[$hword] = 1;
			}
		}
		return $awords;
	}

	/**
	 * @return array
	 * Hyphenated words found without spaces
	 */
	public function Hyphenations() {
		$words = RegexMatches("\b\pL+-\pL+\b", "u", $this->Text());
		$resp = [];
		foreach($words as $word) {
			if ( $word == "" ) {
				continue;
			}
			if( RegexMatch("\p{Greek}", "ui", $word)) {
				continue;
			}
			$word = lowercase( trim( $word ) );
			if ( isset( $resp[ $word ] ) ) {
				$resp[ $word ] += 1;
			} else {
				$resp[ $word ] = 1;
			}
		}
		return $resp;
	}

	public function Letters() {
	    $retn = [];
	    foreach($this->TextSlices() as $slice) {
            $letters = RegexMatches("\pL", "u", $slice);
            foreach($letters as $l) {
                if(isset($retn[$l])) {
                    $retn[$l]++;
                }
                else {
                    $retn[$l] = 1;
                }
            }
        }
	    return $retn;
    }
	public function Words() {
//		$words = RegexMatchesColumns("(\p{L}+)[^-]\b", "u", $this->Text(), 1);
		$words = RegexMatches("\b\p{L}+\b", "u", $this->Text());
		$resp = [ ];
		foreach($words as $word) {
			if($word == "") {
				continue;
			}
			$word = lowercase(trim($word));
			if(isset($resp[$word])) {
				$resp[$word] += 1;
			}
			else {
				$resp[$word] = 1;
			}
		}
		return $resp;
	}

	/**
	 * @param $aregex array | string
	 * @param $arepl array | string
	 * @param string $flags string
	 *
	 * @return array
	 */
	public function ReplaceWords($aregex, $arepl, $flags = "u") {
//		trace($this->textslices);
		$n = RegexReplaceCount($aregex, $arepl, $flags, $this->textslices);
//		trace($n);
//		trace($this->textslices);
//		foreach($this->textslices as $key => $slice) {
//			$n = RegexReplaceCount($aregex, $arepl, $flags, $slice);
//			if($n == 0) {
//				continue;
//			}
//		}
		if($n > 0) {
			$this->SaveRevisedText($n);
		}
		return $n;
	}

	public function Tags() {
		$tags = RegexMatchesColumns(self::TAGREGEX, "us", $this->XMLText());
		return $tags;
	}


	public function XMLText() {
		return $this->xmltext;
	}
	public function Text() {
		if(! is_array($this->TextSlices())) {
			return "";
		}
		else {
			return implode(" ", $this->TextSlices());
		}
	}


	public function HyphenatedWordsOffsets() {
		$rmfos = RegexMatchesFieldsOffsets(HYPHEN_SPACE_REGEX, "us", $this->Text());
		if(count($rmfos) == 0) {
			return [];
		}

		$aret = [];
		foreach($rmfos as $rmfo) {
			$rec =
				[
					"path" => $this->Path(),
					"context" => $rmfo[0][0],
					"offset" => $rmfo[0][1],
					"word" => $rmfo[2][0],
					"prefix" => $rmfo[3][0],
					"suffix" => $rmfo[4][0],
				];
			$aret[] = $rec;
		}
		return $aret;
	}

	/**
	 * @return array &$array
	 */
	public function TextSlices() {
		return $this->textslices;
	}

	private function Header() {
		return $this->header;
	}

	private function Trailer() {
		return $this->trailer;
	}

//	public function WordCount($word) {
//		return count(RegexMatchesColumns($word, "u", $this->Text()));
//	}

	public function RegexCount($regex, $flags) {
		$n = 0;
		foreach($this->textslices as $slice) {
			$n += RegexCount($regex, $flags, $slice);
		}
		return $n;
	}

	public function RegexMatchFieldsArray($regex, $flags = "uis") {
		$matches = RegexMatchesFields($regex, $flags, $this->Text());
		return $matches;
	}

	// TEI
	// returns [word=>count, word=>count, ...]
	public function RegexCounts($regex, $flags) {
		$matches = RegexMatchesFields($regex, $flags, $this->Text());
		$wcs = [];
		if(! $matches || count($matches) == 0) {
			return $wcs;
		}
		foreach($matches as $match) {
			$word = $match[0];
			if(isset($wcs[$word])) {
				$wcs[$word] += 1;
			}
			else {
				$wcs[$word] = 1;
			}
		}
		return $wcs;
	}

	public function HyphenContexts($word) {
		$ctxts = [];
		$txt = $this->Text();
		$rx = "(.{0,40}?)(" . $word . ")(.{0,40}?)";
		$flags = "usi";
		$matches = RegexMatchesFields($rx, $flags, $txt);
		foreach($matches as $match) {
			list($context, $prefix, $word, $suffix) = $match;
			$context = trim(RegexReplace("\s+", " ", "us", $context));
			$word = trim(RegexReplace("\s+", " ", "us", $word));
			$teiname = RegexReplace("\.xml", "", "u", basename($this->Path()));
			$fields = explode("-", $teiname);
			$edition = left($fields[1], 4);
			$volume = right($fields[1], 2);
			$pgseq = $fields[2];
			$pgnum = $fields[3];
			$pgindex = $fields[4];
			$ctxts[] = ["context" => $context,
			            "word" => $word,
			            "prefix" => $prefix,
			            "suffix" => $suffix,
			            "flags" => $flags,
			            "edition" => $edition,
			            "volume" => $volume,
			            "pgseq" => $pgseq,
			            "pgnum" => $pgnum,
			            "pgindex" => $pgindex,
			            "teiname" => $teiname,
			];
		}
		return $ctxts;
	}

	private function OriginalContent() {
		return $this->original_content;
	}
	public function RegexContexts($regex, $flags = "uis", $repl = "$1") {
//		$rm = RegexMatch($regex, $flags, $this->Text());
//		if(! $rm) {
//		    return [];
//        }
        $ctxts = [];
		$rx = "(.{1,20})(" . $regex . ")(.{1,20})";
		foreach($this->TextSlices() as $slice) {
            $matches = RegexMatchesFields($rx, $flags, $slice);
//            if (count($matches) > 0) {
//                trace("415 " . $this->Path());
//                trace($matches);
//            }
            foreach ($matches as $match) {
                list($context, $prefix, $word, $suffix) = $match;
                if (RegexCount("\n", "usi", $context)) ;
                $context = trim(RegexReplace("\s+", " ", "us", $context));
                $word = trim(RegexReplace("\s+", " ", "us", $word));
                $teiname = RegexReplace("\.xml", "", "u", basename($this->Path()));
                $fields = explode("-", $teiname);
                $edition = left($fields[1], 4);
                $volume = right($fields[1], 2);
                $pgseq = $fields[2];
                $pgnum = $fields[3];
                $pgindex = $fields[4];
                $ctxts[] = ["context" => $context,
                    "word" => $word,
                    "prefix" => $prefix,
                    "suffix" => $suffix,
                    "teiname" => $teiname,
                    "edition" => $edition,
                    "volume" => $volume,
                    "pgnum" => $pgnum,
                ];
            }
        }
		return $ctxts;
	}

    /**
     * @param $words array|string
     * @param $repl array|string
     * @return int
     */
    public function ReplaceLineBreakHyphenations($words, $repl) {
        $t = 0;
        foreach($this->TextSlices() as $slice) {
            $slice1 = RegexReplace($words, $repl, "uis", $slice, -1, $n);
            $t += $n;
        }
        if($slice != $slice1) {
            for($i = 0; isset($words[$i]); $i++) {
                trace($words[$i] . "  " . $repl[$i]);
            }
            trace( $this->Path());
            trace( wp_text_diff($slice, $slice1));
        }
        $this->SaveRevisedText($t);
        return $t;
    }
//	public function WordContexts($regex, $flags) {
//		$ctxts = [];
//		$matches = RegexMatchesFields($regex, $flags, $this->Text());
//		if(count($matches) == 0) {
//			return $ctxts;
//		}
//		foreach($matches as $match) {
//			$ctxts[] =
//				[
//					"context" => preg_replace("~\s+~us", " ", $match[0]),
//					"teibase" => basename($this->Path()),
//					"url"     => $this->ImageUrl($this->PgNum()),
//				];
//		}
//		return $ctxts;
//	}


	/**
	 * @return string
	 */
	protected function NewContent() {
		return implode("", $this->contentslices);
	}

	/*
	static public function SortWords(&$words, $sort = "alpha_up") {
		if(! $words || count($words) == 0) {
			return;
		}
		switch($sort) {
			case "alpha_up":
				ksort($words);
				break;
			case "alpha_down":
				krsort($words);
				break;
			case "count_up":
				asort($words);
				break;
			case "count_down":
				arsort($words);
				break;
		}
	}
	*/

	static public function SortWordCounts(&$words, $sort = "alpha_up") {
		switch($sort) {
			case "alpha_up":
				ksort($words);
				break;
			case "alpha_down":
				krsort($words);
				break;
			case "count_up":
				asort($words);
				break;
			case "count_down":
				arsort($words);
				break;
		}
	}
	protected function RevisedTextPath() {
//		return $this->Path() . ".tmp";
		return $this->Path() . ".tmp";
	}

	protected function SaveRevisedText($nchanges) {
        global $context;
//        trace(wp_text_diff($this->original_content, $this->NewContent()));
        $msg = sprintf("%s %s %d", $this->Path(), "array", $nchanges);
        $context->LogXml($msg);
		file_put_contents($this->RevisedTextPath(),
		$this->header . $this->facs . $this->NewContent() . $this->trailer);
	}
}



// example:  "eb09", "t01", "0123"
class TEIPage extends TEI {
	private $code, $index;

	public function __construct($path) {
		list($edition, $code, $index) = decode_page_path($path);
		$this->edition = $edition;
		$this->code = $code;
		$this->index = $index;
		parent::__construct($path, "page", $edition);
	}
}

class TEIEntry extends TEI {

	public function __construct($path) {
//		$regex = "(eb\d\d)(\d\d)-(\d+)-(\d+)-(.+?).xml";
		$regex = "kp-(eb\d\d)(\d\d)-(\d+)-(\d+)-(.+?).xml";
		$match = RegexMatchFields($regex, "u", $path);
		if(count($match) < 6) {
			trace("bad path $path");
			exit;
		}
		$this->edition = $match[1];
		$this->volume = (int) $match[2];
		$this->pgseq = (int) $match[3];
		$this->pgnum = (int) $match[4];
		$this->pgindex = (int) $match[5];
		parent::__construct($path, "entry", $this->edition);
	}

	public function Title() {
		return RegexMatch("<label.*?>(.*?)</label>", "us", $this->XMLText(), 1);
	}

	public function Name() {
		return articlename($this->Title());
	}

	public function Tables() {

	}

//	public function TEIName() {
//		return sprintf("%4s%02d-%04d-%94d-%02d",
//					$this->edition, $this->volume, $this->pgseq, $this->pgnum, $this->pgindex);
//	}
}

class TEIVolume {
	private $edition;
	private $volume;

	public function __construct($edition, $volume) {
		$this->edition = $edition;
		$this->volume = $volume;
	}
}
/**
 * Class TEIEdition
 */
class TEIPhase {
	private $edition;
	private $phase;
	private $entry_paths;
	private $page_paths;
	private $tei_text;
	private $words;
	private $word_counts;
	private $lower_case_word_counts;

	public function __construct($edition, $phase) {
		$this->edition = $edition;
		$this->phase = $phase;
	}


	public function Edition() {
		return $this->edition;
	}

	public function Phase() {
		return $this->phase;
	}

	public function Volumes() {
		global $ebdb;
		$vols = [];

		$sql = "SELECT DISTINCT volume FROM tei_xml_Page
				WHERE edition = ?";
		$args = [&$this->edition];
		$v = $ebdb->SqlRowsPS($sql, $args);
		foreach($v as $val) {
			$vols[] = $val;
		}
		return $vols;
	}

//	public function TeiSourcePaths() {
//		$tpl = build_path( TEIPATH, "entry/{$this->edition}/*/*.xml");
//		return glob($tpl);
//	}

    public function RegexWords($edition, $regex, $flags) {
	    $rws = [];
	    foreach($this->EntryPaths() as $path) {
	        $tei = $this->GetTei($path);
	        $wcs = $tei->RegexCounts($regex, $flags);
	        foreach($wcs as $word => $count) {
	            if(is_set($rws[$word])) {
	                $rwщ[$word] += $count;
                }
                else {
                    $rws[$word] = $count;
                }
            }
        }
	    return $rws;
    }

	public function VolumePages($edition, $volume) {
		global $ebdb;
		$sql = "SELECT edition, volume, pgnum, xmlpath
				FROM tei_xml_page
				WHERE edition = ? AND volume = ?
				ORDER BY pgnum";
		$args = [&$edition, &$volume];
		return $ebdb->SqlRowsPS($sql, $args);
	}


	public function Paths() {
		if($this->phase == "page") {
			return $this->PagePaths();
		}
		else {
			return $this->EntryPaths();
		}
	}
	public function Words() {
		if(! $this->words) {
			$this->words = [];
            foreach($this->Paths() as $path) {
                $tei = $this->GetTei($path);
                $txt = $tei->Text();
                $matches = RegexMatchesColumns("\p{L}+", "u", $txt);
                foreach($matches as $match) {
                    if(isset($this->words[$match])) {
                        $this->words[$match]++;
                    }
                    else {
                        $this->words[$match] = 1;
                    }
                }
            }
		}
		return $this->words;
	}


    /**
     * @return array
     */
	public function AnyList() {
	    return [];
    }
	/**
	 * @param $rxrecs array of objects
	 * (e.g. word=>count)
	 * @param $repl string
	 * @param $flags string
	 *
	 * @return int
	 */
	public function ReplaceWords($rxrecs, $repl, $flags) {
		$paths = $this->Paths();
//		$arx = [];
//		foreach($rxrecs as $rec) {
//			if($rec->apply) {
//				$arx[] = "~".$rec->word."~su";
//				$arp[] = $rec->repltext;
//			}
//		}

		$n = 0;
		foreach($paths as $path) {
			$tei = $this->GetTei( $path );
			$nr  = $tei->ReplaceWords( $rxrecs, $repl, $flags );
			if(! is_integer($nr)) {
				trace("Non-integer:");
				trace($nr);
				exit;
			}
			$n += $nr;
		}
		return $n;
	}

    /**
     * @param $words array
     * @param $repls array
     * @return int
     *
     * The $words array contains the hyphenated-spaced-words to find and replace.
     * e.g. "know-\s+ledge"
     * The $repls array contains
     * e.g. "$1$2
     */
	public function ReplaceLineBreakHyphenations($words, $repls) {
		$t = 0;
		foreach($this->Paths() as $path) {
			$tei = $this->GetTei($path);
			$n = $tei->ReplaceLineBreakHyphenations($words, $repls);
			$t += $n;
		}
		return $t;

	}

	/*
	private function LisToRegexArray($list, $transform, $flags) {
		$arx = [];
		$arp = [];

		foreach($list as $item) {
			$arx[] = "~{$item}~{$flags}";
			$arp[] = is_callable($transform)
						? call_user_func($transform, $item)
						: $transform;
			return [$arx, $arp];
		}

	}
	*/

//	public function ReplaceRegex($regex, $repl, $flags) {
//		$n = 0;
//		foreach($this->Paths() as $path) {
//			$tei = $this->GetTei($path);
//			$n += $tei->ReplaceRegex($regex, $repl, $flags);
//		}
//		return $n;
//	}

	// TEIPhase ...

	public function RegexMatchFieldsArray($regex, $flags = "u") {
		$rcs = [];
		foreach($this->Paths() as $path) {
			$tei     = $this->GetTei( $path );
			$afields = $tei->RegexMatchFieldsArray( $regex, $flags );
			if(count($afields)) {
//			    trace($afields);
                $rcs     = array_merge( $rcs, $afields );
            }
		}
		return $rcs;
	}

	public function HyphenContexts($word) {
		$ctxts = [];
		foreach($this->Paths() as $path) {
			$tei      = $this->GetTei( ( $path ) );
			$contexts = $tei->HyphenContexts( $word );
			$ctxts    = array_merge( $ctxts, $contexts );
		}
	}
	public function RegexContexts($regex, $flags = "u", $repl = "$0", $sort = "pgseq_up") {
		$ctxts = [];
		foreach($this->Paths() as $path) {
			$tei = $this->GetTei(($path));
//			$contexts = $tei->RegexMatchFieldsArray($regex, $flags, $repl)  ;
            $contexts = $tei->RegexContexts($regex, $flags, $repl)  ;
//            if(count($contexts) > 0) {
//                trace($path);
//                trace($contexts);
			$ctxts = array_merge($ctxts, $contexts);
		}
//		list($w, $d) = explode("_", $sort);
//		$this->FieldSort($ctxts, $w, $d);
		return $ctxts;
	}

	public function UserMayManage() {
		return TRUE;
	}

	public function EntryFileCount() {
		return count($this->EntryPaths());
	}

	public function PageFileCount() {
		return count($this->PagePaths());
	}

	public function WordHypWordCounts() {
		$regex = "\p{L}+\-\p{L}+";
		$ret = [];
		foreach($this->EntryPaths() as $path) {
			$tei = new TEIEntry($path);
			$ret = array_merge($ret, $tei->RegexCounts($regex, "us"));
		}
		return $ret;
	}

    /**
     * @return array
     */
	public function Hyphenations() {
		$words   = [ ];
		$paths = $this->Paths();
		foreach ( $paths as $path ) {
			$tei   = new TeiPage( $path, $this->edition );
			$hypwords = $tei->Hyphenations();
			if(count($hypwords) == 0) {
				continue;
			}
			foreach ( $hypwords as $hypword => $wcount ) {
				if ( isset( $words[ $hypword ] ) ) {
					$words[ $hypword ] += $wcount;
				} else {
					$words[ $hypword ] = $wcount;
				}
			}
		}
		return $words;
	}

	public function LowercaseWordCounts($sort="count_down") {
		if(! $this->lower_case_word_counts) {
			$this->lower_case_word_counts = [ ];
			$paths = $this->Paths();
			foreach ( $paths as $path ) {
				if($this->phase == "page") {
					$tei = new TeiPage( $path );
				}
				else {
					$tei = new TEIEntry( $path );
				}
				$teiwords = $tei->Words();
				foreach ( $teiwords as $word => $wcount ) {
					if ( isset( $this->lower_case_word_counts[ $word ] ) ) {
						$this->lower_case_word_counts[ $word ] += $wcount;
					} else {
						$this->lower_case_word_counts[ $word ] = $wcount;
					}
				}
			}
			TEI::SortWordCounts($teiwords, $sort);
		}
		return $this->lower_case_word_counts;

	}

	private function EntryWordCounts() {
		if(! $this->word_counts) {
			$this->word_counts = [ ];
			$paths = $this->EntryPaths();
			foreach ( $paths as $path ) {
				$tei      = new TeiEntry( $path, $this->edition );
				$teiwords = $tei->Words();
				foreach ( $teiwords as $word => $count ) {
					if ( isset( $this->word_counts[ $word ] ) ) {
						$this->word_counts[ $word ] += $count;
					} else {
						$this->word_counts[ $word ] = $count;
					}
				}
			}
		}
		return $this->word_counts;
	}
//	private function LowerCaseEntryWordCounts($sort = "count_down") {
//		$wc = [ ];
//		$paths = $this->EntryPaths();
//		foreach ( $paths as $path ) {
//			$tei   = new TeiEntry( $path, $this->edition );
//			$words = $tei->Words();
//			foreach ( $words as $word => $count ) {
//				$word = lowercase( $word );
//				if ( isset( $wc[ $word ] ) ) {
//					$wc[ $word ] += $count;
//				} else {
//					$wc[ $word ] = $count;
//				}
//			}
//			TEI::SortWordCounts( $wc, $sort );
//		}
//		return $wc;
//	}

	private function PageWordCounts() {
		$words   = [ ];
		$paths = $this->PagePaths();
		foreach ( $paths as $path ) {
			$tei   = new TeiPage( $path, $this->edition );
			$pwords = $tei->Words();
			foreach ( $pwords as $word => $wcount ) {
				if ( isset( $words[ $word ] ) ) {
					$words[ $word ] += $wcount;
				} else {
					$words[ $word ] = $wcount;
				}
			}
		}
		return $words;
	}

	public function WordCounts($sort = "counts_down") {
		if(! $this->word_counts) {
			if($this->phase == "page") {
				$this->word_counts = $this->PageWordCounts();
			}
			else {
				$this->word_counts = $this->EntryWordCounts();
			}
		}
		TEI::SortWordCounts($word_counts, $sort);
		return $this->word_counts;
	}

	public function AdHocWordCountArray($wlist, $sort = "count_down") {
			$ahwords = [];
			foreach($wlist as $word) {
				if(isset($ahwords[$word])) {
					$ahwords[$word] = $$word;
				}
				else {
					$ahwords[$word] = 0;
				}
			}
		TEI::SortWordCounts($ahwords, $sort);
		return $ahwords;
	}

	public function GetTei($path) {
		if($this->phase == "page") {
			return new TeiPage($path);
		}
		else if($this->phase == "entry") {
			return new TeiEntry($path, $this->edition);
		}
		else {
			trace("Faulty page/entry  in GetTei");
			exit;
		}
	}

	// TEIPhase
	// returns [word=>count, ...],
	public function RegexMatchCounts($regex, $flags = "u") {
		$rcs = [];
		foreach($this->Paths() as $path) {
			$tei = $this->GetTei($path);
			$trcs = $tei->RegexCounts($regex, $flags);
			// trcs is [word=>count,
			foreach($trcs as $w => $c) {
				if(isset($rcs[$w])) {
					$rcs[$w] += $c;
				}
				else {
					$rcs[$w] = $c;
				}
			}
		}
		$res = [];
		foreach($rcs as $w => $c) {
			$res[] = ["word" => $w, "count" => $c];
		}
		return $res;
	}

	// TEIPhase
	public function RegexCountRecords($regex, $repl, $flags, $sort = "alpha_down") {
		$av = $this->RegexMatchCounts($regex, $flags);
		$gwords = $this->LowercaseWordCounts();
		$aret = [];
		// av  [[word=>str, count=>c], ...
		foreach($av as $rc) {
			$word = $rc["word"];
			$count = $rc["count"];
			$repltext = RegexReplace($regex, $repl, $flags, $word);
			$gkey = lowercase($repltext);
			if(isset($gwords[$gkey])) {
				$gcount = $gwords[ $gkey ];
			}
			else {
				$gcount = 0;
			}
			$aret[] =
				["word" => $word,
				 "count" => $count,
				 "gword" => $gkey,
				 "gcount" => $gcount,
				 "repl" => $repl,
				 "repltext" => $repltext,
				 "apply" => ($count < $gcount ? 1 : 0),
				];
		}
		switch($sort) {
			case "alpha_up":
				uasort($aret,
					function ($a, $b) {
						return $a["word"] > $b["word"];
					});
				break;
			case "alpha_down":
				uasort($aret,
					function ($a, $b) {
						return $a["word"] < $b["word"];
					});
				break;
			case "count_up":
				uasort($aret,
					function ($a, $b) {
						if($a["count"] == $b["count"]) {
							return $a["gcount"] > $b["gcount"];
						}
						return $a["count"] > $b["count"];
					});
				break;
			case "count_down":
				uasort($aret,
					function ($a, $b) {
						if($a["count"] == $b["count"]) {
							return $a["gcount"] < $b["gcount"];
						}
						return $a["count"] < $b["count"];
					});
				break;
		}
		return $aret;
	}

	// TEIPhase

	public function DeHyphenateWords($words) {
		$n = 0;
		list($w1, $w2) = $words;
		foreach($this->EntryPaths() as $path) {
			$tei = new TeiEntry($path);
			$tpl = "{$w1}-\s+{$w2}";
			$rpl = $w1 . $w2;
			$n += $tei->ReplaceWords([$tpl, $rpl], "us");
		}
		return $n;
	}

/**
 * @param $sort string
 * @return array
    "words" => $words,
    "count" => $count,
    "gword" => $gword,
    "gcount" => $gcount,
    "hypword" => $hypword,
    "hypcount" => $hypcount,
 */
    public function HyphenatedWordRecords($sort = "count_down") {
		$gwords = $this->LowercaseWordCounts();
		$hypwords = $this->Hyphenations();
//		$regex = "(\pL+)-\s+(\pL+)";
        $regex = HYPHEN_REGEX;
		$flags = "us";
		$mfa = $this->RegexMatchFieldsArray($regex, $flags);
		$awords = [];
		foreach($mfa as $mf) {
//			$words = lowercase($mf[0]);
            $words = trim($mf[0]);
			if(RegexMatch("\p{Greek}", "ui", $words)) {
				continue;
			}
			$words = RegexReplace("\s+", " ", "uis", $words);
			if(mb_strlen($words) <= 5) {
			    continue;
            }
			if ( isset( $awords[ $words ] ) ) {
				$awords[ $words ] ++;
			} else {
				$awords[ $words ] = 1;
			}
		}

		arsort($awords);

		$aret = [];
		$ntot = 0; $njoin = 0; $nhyph = 0;
		foreach($awords as $words => $count) {
			$gword = lowercase(RegexReplace("-\s*", "", "us", $words));
			$gcount = isset($gwords[$gword]) ? $gwords[$gword] : 0;
			$hypword = RegexReplace("-\s*", "-", "us", $words);
			$hypcount = isset($hypwords[$hypword]) ? $hypwords[$hypword] : 0;

			$aret[] = [
				"words" => $words,
				"count" => $count,
				"gword" => $gword,
				"gcount" => $gcount,
				"hypword" => $hypword,
				"hypcount" => $hypcount,
			];

			if($gcount > $hypcount) {
				$njoin += $count;
			}
			else {
				$nhyph += $count;
			}
			$ntot += $count;
		}

		switch($sort) {
			case "alpha_down":
				usort($aret, function($a, $b) { return $a["words"] < $b["words"] ; });
				break;
			case "alpha_up":
				usort($aret, function($a, $b) { return $a["words"] > $b["words"] ; });
				break;
			case "count_down":
				usort($aret, function($a, $b) { return $a["count"] < $b["count"] ; });
				break;
			case "count_up":
				usort($aret, function($a, $b) { return $a["count"] > $b["count"] ; });
				break;
			case "gcount_down":
				usort($aret, function($a, $b) { return $a["gcount"] < $b["gcount"] ; });
				break;
			case "gcount_up":
				usort($aret, function($a, $b) { return $a["count"] > $b["count"] ; });
				break;
		}
		return $aret;
	}

	private function FieldSort($awords, $fieldname, $direction = "up") {
		global $fname;
		$fname = $fieldname;
		if($direction == "down") {
			usort($awords, function($a, $b) {global $fname;  return $a[$fname] < $b[$fname] ; });
		}
		else {
			usort($awords, function($a, $b) {global $fname;  return $a[$fname] > $b[$fname] ; });
		}
	}


	public function HyphenatedWordsOffsets() {
		$aret = [];
		foreach($this->Paths() as $path) {
			$tei = $this->GetTei( $path );
			$hcos = $tei->HyphenatedWordsOffsets();
			if(count($hcos) > 0 ) {
				$aret = array_merge( $aret, $hcos );
			}
		}
		return $aret;
	}

	public function SavePhaseText() {
		file_put_contents( build_path($this->PhaseDirectory(), $this->Edition().".txt"), $this->Text() );
	}

	public function PhaseDirectory() {
		if($this->phase == "entry") {
			return $this->EntryDirectory();
		}
		else {
			return $this->PageDirectory();
		}
	}

	public function Text() {
		if(! $this->tei_text) {
			$this->tei_text = "";
			foreach ( $this->Paths() as $path ) {
				$tei = $this->GetTei( $path );
				$this->tei_text .= (PHP_EOL . PHP_EOL . wordwrap($tei->Text(), 120));
			}
		}
		return $this->tei_text;
	}


		// TEIPhase
	public function RegexFileRegexCounts($regexpath, $separator = "\t") {
		/** @noinspection PhpUnusedLocalVariableInspection */

		$aregexes = [];
		$rows = file( $regexpath );
		foreach($rows as $row) {
			$a = RegexSplit( $separator, "u", trim($row) );
			if ( count( $a ) < 2 ) {
				continue;
			}
			list($regex, $repl) = $a;
			$count = $this->RegexMatchCounts($regex);
			$aregex = ["word"=>$regex, "repl"=>$repl, "count"=>$count];
			$aregexes[] = $aregex;
		}
		return $aregexes;
	}

		// TEIPhase
	public function LongEssRegexCounts($regex, $repl, $sort="count_down") {
		$gwords = $this->LowercaseWordCounts();
		$eb7 = new TEIPhase("eb07", "page");
		$eb7words = $eb7->LowercaseWordCounts();
		$wcs = $this->RegexMatchCounts($regex, "uis");
		// returned wcs[word] = count
		$aret = [];
		foreach($wcs as $wc) {
			$word = $wc["word"];
			$count = $wc["count"];
			$repltext = RegexReplace($regex, $repl, "uis", $word);
			$gkey = lowercase($repltext);
			$eb7key = RegexReplace(LONGESS, "s", "uis", $gkey);
			$gcount = isset($gwords[$gkey]) ? $gwords[$gkey] : 0;
			$eb7count = isset($eb7words[$eb7key]) ? $eb7words[$eb7key] : 0;

			$aret[] =
				["word" => $word,
				 "count" => $count,
				 "gword" => $gkey,
				 "gcount" => $gcount,
				 "eb7word" => $eb7key,
				 "eb7count" => $eb7count,
				 "repl" => $repl,
				 "repltext" => $repltext,
				 "apply" => ($count < $gcount || $count < $eb7count) ? 1 : 0,
				];
		}
		switch($sort) {
			case "alpha_down":
				usort($aret, function($a, $b) {
					return lower($a["word"]) < lower($b["word"]) ;
				});
				break;
			case "alpha_up":
				usort($aret, function($a, $b) {
					return $a["word"] > $b["word"] ;
				});
				break;
			case "count_down":
				usort($aret, function($a, $b) {
					return $a["count"] < $b["count"] ;
				});
				break;
			case "count_up":
				usort($aret, function($a, $b) {
					return $a["count"] > $b["count"] ;
				});
				break;
		}
		return $aret;
	}

	public function TeiPaths() {
		return $this->ModePaths($this->phase);
	}

	private function ModePaths($mode) {
		switch($mode) {
			case "page":
				return $this->PagePaths();
				break;
			case "entry":
				return $this->EntryPaths();
				break;
			default:
				trace("ModePaths mode = $mode undefined");
				exit;
		}
	}

	public function EntryDirectory() {
		return build_path(TEIPATH, "entry/".$this->Edition());
	}
	public function PageDirectory() {
		return build_path(TEIPATH, "page/".$this->Edition());
	}

	public function EntryPaths() {
		if(! $this->entry_paths) {
			$tpl = build_path( TEIPATH, "entry/".$this->Edition()."/[a-z][0-9]*/kp-*.xml");
			$this->entry_paths = glob($tpl);
		}
		return $this->entry_paths;
	}
	public function PagePaths() {
		if(! $this->page_paths) {
			$tpl = build_path( TEIPATH, "page/{$this->edition}/*/3-page-tei/*.xml");
			$this->page_paths = glob($tpl);
		}
		return $this->page_paths;
	}

//	public function WordContexts($word, $flags) {
//		$ctxts  = [];
//		foreach($this->Paths() as $path) {
//			$tei = new TeiPage($path);
//			$cs = $tei->WordContexts($word, $flags);
//			if(count($cs) > 0) {
//				$ctxts = array_merge($ctxts, $cs);
//			}
//		}
//		return $ctxts;
//	}

	public function LabelExists($label) {
		global $ebdb;
		$sql = "SELECT 1 FROM teimap
				WHERE edition = ? AND label = ?";
		$args = [&$this->edition, &$label];
		return $ebdb->SqlExistsPS($sql, $args);
	}

	public function GoodHyphensPath() {
		$path = build_path(ENTRY_PATH, $this->Edition());
		$path =  build_path($path, "hyphenated.words");
		touch($path);
		return($path);
	}
	public function GoodHyphenatedWords() {
		$words = file($this->GoodHyphensPath());
		$a = [];
		foreach($words as $word) {
			$word = lowercase($word);
			if($word == "") {
				continue;
			}
			if($a[$word]) {
				continue;
			}
			$a[] = $word;
		}
		return $a;
	}
	public function AddGoodHyphenatedWords($awords) {
		$ary = array_merge($this->GoodHyphenatedWords(), $awords);
		file_put_contents($this->GoodHyphensPath(), implode(PHP_EOL, $ary));
	}

    public function Letters() {
        $ltrs = [];
        foreach($this->Paths() as $path) {
            $tei = $this->GetTei($path);
            foreach($tei->Letters() as $l => $c) {
                if(isset($ltrs[$l])) {
                    $ltrs[$l] += $c;
                }
                else {
                    $ltrs[$l] = $c;
                }
            }
        }
        return $ltrs;
    }

}       // end TEIPhase

class Rx {
	private $needle;
	private $repl;
	private $desc;
	private $matchcount = 0;
	private $wordcount = 0;

	public function __construct($needle, $repl, $desc) {
		$this->needle = trim($needle);
		$this->repl = trim($repl);
		$this->desc = trim($desc);
	}

	public function Needle() {
		return $this->needle;
	}

	public function Desc() {
		return $this->desc;
	}

	public function Repl() {
		return $this->repl;
	}

	public function DataRow() {
		return ["descr" => $this->Desc(),
		        "regex" => $this->Needle(),
		        "repl" => $this->Repl(),
		        "count" => $this->RegexCount(),
		        "wordcount" => $this->WordCount(),
		];
	}
	public function RxEcho() {
		echo PHP_EOL . sprintf("<br />%-20s %-20s %-20s %7d/%5d",
				$this->desc, $this->needle, $this->repl, $this->matchcount, $this->wordcount);
	}

	public function IncrCount($matchcount) {
		$this->matchcount += $matchcount;
	}

	public function IncrWords($n) {
		$this->wordcount += $n;
	}

	public function WordCount() {
		return $this->wordcount;
	}
	public function RegexCount() {
		return $this->matchcount;
	}
}

class RegexContext implements JsonSerializable {
	private $regex;
	private $repl;
	private $flags;
	private $word;
	private $prefix;
	private $suffix;
	private $context;
	private $editionid;
	private $volume;
	private $pgnum;
	private $phase;
	private $filename;
	private $offset;
	public function __construct($context, $word, $prefix, $suffix, $regex, $repl, $flags, $editionid, $phase,
		$volume, $pgnum, $filename, $offset = null ) {
		$this->context = $context;
		$this->word = $word;
		$this->prefix = $prefix;
		$this->suffix = $word;
		$this->regex = $regex;
		$this->repl = $repl;
		$this->flags = $flags;
		$this->editionid = $editionid;
		$this->phase = $phase;
		$this->volume = $volume;
		$this->pgnum = $pgnum;
		$this->filename = $filename;
		$this->offset = $offset;
	}

	public function Context() {
		return $this->context;
	}

	public function Prefix() {
		return $this->prefix;
	}
	public function Suffix() {
		return $this->suffix;
	}
	public function Pgnum() {
		return $this->pgnum;
	}
	public function FileName() {
		return $this->filename;
	}
	public function NewContext() {
		return RegexReplace($this->Regex(), $this->Repl(), $this->Flags(), $this->Context());
	}
	public function Word() {
		return $this->word;
	}
	public function Regex() {
		return $this->regex;
	}
	public function Repl() {
		return $this->repl;
	}
	public function Flags() {
		return $this->flags;
	}
	public function Edition() {
		return $this->editionid;
	}
	public function Volume() {
		return $this->volume;
	}

	public function JsonSerialize() {
		return ["word" => $this->Word(),
		        "regex" => $this->Regex(),
		        "repl" => $this->Repl(),
		        "context" => $this->Context(),
		        "newcontext" => $this->NewContext(),
		        "prefix" => $this->Prefix(),
		        "suffix" => $this->Suffix(),
		        "filename" => $this->Filename(),
		        "edition" => $this->Edition(),
		        "volume" => $this->Volume(),
		        "pgnum" => $this->Pgnum(),
		];
	}
}
