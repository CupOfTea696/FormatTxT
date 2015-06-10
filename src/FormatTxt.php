<?php namespace CupOfTea\FormatTxt;

use Kuz\Text;
use CupOfTea\Package\Package;

use CupOfTea\FromatTxt\Exceptions\InvalidAttributeException;

class FormatTxt
{
    use Package;
    
    /**
     * Package Info
     *
     * @const string
     */
    const PACKAGE = 'CupOfTea/FormatTxT';
    const VERSION = '1.1.0';
    
    /**
     * Beautify a string into paragraphs and clickable links
     * @param string $text
     * @return string
     */
    public static function beautify($text)
    {
        return self::nl2p(self::linkify($text));
    }
    
    /**
     * Format a string by replacing placeholder values
     *
     * @param   string  $subject
     * @param   array   $replacements
     * @param   string  $prefix
     * @return  string
     * @see https://github.com/akuzemchak/text
     */
    public static function format($subject, array $replacements, $prefix = ':')
    {
        return Text::format($subject, $replacements, $prefix);
    }
    
    /**
     * Convert line breaks into <p> and <br> tags
     *
     * @param   string  $text
     * @param   string  $xhtml
     * @return  string
     * @see https://github.com/akuzemchak/text
     */
    public static function nl2p($text, $xhtml = false)
    {
        return Text::nl2p($text, $xhtml);
    }
    
    /**
     * Limit the amount of paragraphs
     *
     * @param   string  $text
     * @param   string  $xhtml
     * @return  string
     * @see https://github.com/akuzemchak/text
     */
    public static function p_limit($text, $limit)
    {
        if (strpos($text, '<p>') !== false) {
            return preg_replace('/((?:<p>.*?<\\/p>){0,' . $limit . '}).*/s', '$1', $text);
        } else {
            $text = str_replace("\r\n", "\n", $text);
            return preg_replace('/((?:.*?\n\n+){0,' . $limit . '}).*/s', '$1', $text);
        }
    }
    
    /**
     * Linkify Text
     *
     * @param  [[Type]] $text                  [[Description]]
     * @param  [[Type]] [$protocols = ['http'] [[Description]]
     * @param  [[Type]] 'https'                [[Description]]
     * @param  [[Type]] 'ftp'                  [[Description]]
     * @param  [[Type]] 'email']               [[Description]]
     * @param  [[Type]] [$options = []]        [[Description]]
     * @return string   [[Description]]
     * @see https://github.com/misd-service-development/php-linkify
     */
    public static function linkify($text, $protocols = ['http', 'https', 'ftp', 'email'], $options = [])
    {
        $options = array_merge_recursive([
            'strip_scheme' => true,
            'max_length' => 40,
            'attributes' => [],
        ], $options);
        
        $emails = false;
        if (in_array('email', $protocols)) {
            $protocols = array_diff($protocols, ['email']);
            $emails = true;
        }
        
        $ignoreTags = ['head', 'link', 'a', 'script', 'style', 'code', 'pre', 'select', 'textarea', 'button'];
        
        $attributes = '';
        foreach ($options['attributes'] as $attr => $value) {
            if (strpos($value, '"') !== false && strpos($value, "'") !== false) {
                throw new InvalidAttributeException('The value for ' . $attr . ' contains both single and double quotes (\' and ").');
            } elseif (strpos($value, '"') !== false) {
                $attributes .= ' ' . $attr . '="' . $value . '"';
            } else {
                $attributes .= " " . $attr . "='" . $value . "'";
            }
        }
        $options['attributes'] = $attributes;
        
        $pattern = '~(?xi)
              (?:
                ((' . implode('|', $protocols) . '):(//)?)                    # scheme://
                |                                  #   or
                www\d{0,3}\.                       # "www.", "www1.", "www2." ... "www999."
                |                                  #   or
                www\-                              # "www-"
                |                                  #   or
                [a-z0-9.\-]+\.[a-z]{2,}      # looks like domain name
              )
              (?:                                  # Zero or more:
                [^\s()<>]+                         # Run of non-space, non-()<>
                |                                  #   or
                \(([^\s()<>]+|(\([^\s()<>]+\)))*\) # balanced parens, up to 2 levels
              )*
              (?:                                  # End with:
                \(([^\s()<>]+|(\([^\s()<>]+\)))*\) # balanced parens, up to 2 levels
                |                                  #   or
                [^\s`!\-()\[\]{};:\'".,<>?«»“”‘’]  # not a space or one of these punct chars
              )
        ~';
        
        $callback = function ($match) use ($options, $protocols) {
            $caption = $url = $match[0];
            $pattern = "~^((" . implode('|', $protocols) . "):(//)?)~";
            if (0 === preg_match($pattern, $url)) {
                $url = 'http://' . $url;
            } elseif($options['strip_scheme']) {
                $caption = preg_replace($pattern, '', $url);
                $caption = preg_replace('/^www\./', '', $caption);
                
                if (strlen($caption) > $options['max_length']) {
                    $caption = substr($caption, 0, $options['max_length'] - 1) . '&hellip;';
                }
            }
            if (isset($options['callback'])) {
                $cb = $options['callback']($url, $caption, false);
                if (!is_null($cb)) {
                    return $cb;
                }
            }
            
            return '<a href="' . $url . '"' . $options['attributes'] . '>' . $caption . '</a>';
        };
        
        $email_pattern = '~(?xi)
                \b
                (?<!=)           # Not part of a query string
                [A-Z0-9._\'%+-]+ # Username
                @                # At
                [A-Z0-9.-]+      # Domain
                \.               # Dot
                [A-Z]{2,4}       # Something
        ~';
        
        $email_callback = function ($match) use ($options) {
            if (isset($options['callback'])) {
                $cb = $options['callback']($match[0], $match[0], true);
                if (!is_null($cb)) {
                    return $cb;
                }
            }
            
            $email = self::email($match[0]);
            $mailto = self::obfuscate('mailto:') . $email;
            
            return '<a href="' . $mailto . '"' . $options['attributes'] . '>' . $email . '</a>';
        };
        
        $chunks = preg_split('/(<.+?>)/is', $text, 0, PREG_SPLIT_DELIM_CAPTURE);
        $openTag = null;
        
        for ($i = 0; $i < count($chunks); $i++) {
            if ($i % 2 === 0) { // even numbers are text
                // Only process this chunk if there are no unclosed $ignoreTags
                if ($openTag === null) {
                    if (true === $emails) {
                        $chunks[$i] = preg_replace_callback($email_pattern, $email_callback, $chunks[$i]);
                    }
                    
                    $chunks[$i] = preg_replace_callback($pattern, $callback, $chunks[$i]);
                }
            } else { // odd numbers are tags
                // Only process this tag if there are no unclosed $ignoreTags
                if ($openTag === null) {
                    // Check whether this tag is contained in $ignoreTags and is not self-closing
                    if (preg_match("`<(" . implode('|', $ignoreTags) . ").*(?<!/)>$`is", $chunks[$i], $matches)) {
                        $openTag = $matches[1];
                    }
                } else {
                    // Otherwise, check whether this is the closing tag for $openTag.
                    if (preg_match('`</\s*' . $openTag . '>`i', $chunks[$i], $matches)) {
                        $openTag = null;
                    }
                }
            }
        }
        
        return implode($chunks);
    }
    
    /**
	 * Obfuscate a string to prevent spam-bots from sniffing it.
	 *
	 * @param  string  $value
	 * @return string
	 * @see https://github.com/illuminate/html/blob/master/HtmlBuilder.php
	 */
    public static function obfuscate($value)
	{
		$safe = '';
        
		foreach (str_split($value) as $letter) {
			if (ord($letter) > 128) return $letter;
			// To properly obfuscate the value, we will randomly convert each letter to
			// its entity or hexadecimal representation, keeping a bot from sniffing
			// the randomly obfuscated letters out of the string on the responses.
			switch (rand(1, 3)) {
				case 1:
					$safe .= '&#'.ord($letter).';';
                    break;
				case 2:
					$safe .= '&#x'.dechex(ord($letter)).';';
                    break;
				case 3:
					$safe .= $letter;
                    break;
			}
		}
        
		return $safe;
	}
    
    /**
	 * Obfuscate an e-mail address to prevent spam-bots from sniffing it.
	 *
	 * @param  string  $email
	 * @return string
	 * @see https://github.com/illuminate/html/blob/master/HtmlBuilder.php
	 */
    public static function email($email)
    {
        return str_replace('@', '&#64;', self::obfuscate($email));
    }
    
    /**
	 * Limit the number of characters in a string.
	 *
	 * @param  string  $value
	 * @param  int     $limit
	 * @param  string  $end
	 * @return string
	 */
	public static function str_limit($value, $limit = 100, $end = '...')
	{
		if (mb_strlen($value) <= $limit) return $value;
		return rtrim(mb_substr($value, 0, $limit, 'UTF-8')).$end;
	}
    
    public static function number_format($float, $decimals = 9999, $fixed_decimals = false, $dec_point = '.', $thousands_sep = ',')
    {
        if (!is_numeric($float))
            $float = intval($float);
        
        $float = number_format($float, 9999, $dec_point, $thousands_sep);
        
        if (!$fixed_decimals) {
            $float = preg_replace('/(?:\.0+|(\.\d+?)0+)$/', '$1', $float);
        }
        
        return $float;
    }
    
}
