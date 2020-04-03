<?php

namespace XF\BbCode\Renderer;

use XF\BbCode\Traverser;
use XF\Str\Formatter;
use XF\Template\Templater;

class Html extends AbstractRenderer
{
	protected $trimAfter = 0;

	protected $mediaSites = [];

	protected $imageTemplate = '<img src="%1$s" class="bbImage" alt="" data-url="%2$s" />';

	protected $allowedUrlProtocolRegex = '#^(https?|ftp)://#i';

	protected $allowedCodeLanguages;

	/**
	 * @var Formatter
	 */
	protected $formatter;

	/**
	 * @var Templater
	 */
	protected $templater;

	public function __construct(Formatter $formatter, Templater $templater)
	{
		$this->formatter = $formatter;
		$this->templater = $templater;
		$this->addDefaultTags();
	}

	public function getTemplater()
	{
		return $this->templater;
	}

	public function setTemplater(Templater $templater)
	{
		$this->templater = $templater;
	}

	public function addDefaultTags()
	{
		$this->addTag('b', ['replace' => ['<b>', '</b>']]);
		$this->addTag('i', ['replace' => ['<i>', '</i>']]);
		$this->addTag('u', ['replace' => ['<span style="text-decoration: underline">', '</span>']]);
		$this->addTag('s', ['replace' => ['<span style="text-decoration: line-through">', '</span>']]);
		$this->addTag('color', ['replace' => ['<span style="color: %s">', '</span>']]);

		$this->addTag('font', ['callback' => 'renderTagFont']);
		$this->addTag('size', ['callback' => 'renderTagSize']);
		$this->addTag('url', ['callback' => 'renderTagUrl']);
		$this->addTag('email', ['callback' => 'renderTagEmail']);

		$this->addTag('left', [
			'callback' => 'renderTagAlign',
			'trimAfter' => 1
		]);
		$this->addTag('center', [
			'callback' => 'renderTagAlign',
			'trimAfter' => 1
		]);
		$this->addTag('right', [
			'callback' => 'renderTagAlign',
			'trimAfter' => 1
		]);

		$this->addTag('indent', [
			'callback' => 'renderTagIndent',
			'trimAfter' => 1
		]);

		$this->addTag('img', [
			'callback' => 'renderTagImage'
		]);

		$this->addTag('quote', [
			'callback' => 'renderTagQuote',
			'trimAfter' => 2
		]);

		$this->addTag('code', [
			'callback' => 'renderTagCode',
			'stopBreakConversion' => true,
			'trimAfter' => 2
		]);

		$this->addTag('icode', [
			'callback' => 'renderTagInlineCode'
		]);

		$this->addTag('php', [
			'callback' => 'renderTagPhp',
			'stopBreakConversion' => true,
			'trimAfter' => 2
		]);

		$this->addTag('html', [
			'callback' => 'renderTagHtml',
			'stopBreakConversion' => true,
			'trimAfter' => 2
		]);

		$this->addTag('list', [
			'callback' => 'renderTagList',
			'trimAfter' => 1
		]);

		$this->addTag('plain', [
			'replace' => ['', '']
		]);

		$this->addTag('media', [
			'callback' => 'renderTagMedia',
			'trimAfter' => 1
		]);

		$this->addTag('spoiler', [
			'callback' => 'renderTagSpoiler',
			'trimAfter' => 1
		]);

		$this->addTag('attach', [
			'callback' => 'renderTagAttach',
		]);

		$this->addTag('user', [
			'callback' => 'renderTagUser'
		]);
	}

	public function getCustomTagConfig(array $tag)
	{
		$output = [];

		if ($tag['bb_code_mode'] == 'replace')
		{
			$output['replace'] = $tag['replace_html'];
		}
		else if ($tag['bb_code_mode'] == 'callback')
		{
			$output['callback'] = [$tag['callback_class'], $tag['callback_method']];
		}

		if ($tag['trim_lines_after'])
		{
			$output['trimAfter'] = $tag['trim_lines_after'];
		}

		if ($tag['disable_nl2br'])
		{
			$output['stopBreakConversion'] = true;
		}

		if ($tag['allow_empty'])
		{
			$output['keepEmpty'] = true;
		}

		return $output;
	}

	public function addMediaSite($siteId, array $config)
	{
		$this->mediaSites[$siteId] = $config;
	}

	public function addMediaSites(array $sites)
	{
		foreach ($sites AS $siteId => $site)
		{
			$this->addMediaSite($siteId, $site);
		}
	}

	public function getDefaultOptions()
	{
		return [
			'stopSmilies' => 0,
			'stopBreakConversion' => 0,
			'shortenUrl' => true,
			'noFollowUrl' => true,
			'noProxy' => false,
			'attachments' => [],
			'viewAttachments' => false,
			'user' => null,
			'entity' => null,
			'lightbox' => true
		];
	}

	protected function setupRenderOptions(array $ast, array &$options)
	{
		if (
			!empty($options['user'])
			&& $options['user'] instanceof \XF\Entity\User
			&& $options['user']->isLinkTrusted()
		)
		{
			$options['noFollowUrl'] = false;
		}
	}

	protected function setupRender(array $ast, array $options)
	{
		$this->trimAfter = 0;
	}

	public function renderTag(array $tag, array $options)
	{
		$this->trimAfter = 0;

		$tagName = $tag['tag'];
		if (!isset($this->tags[$tagName]))
		{
			return $this->renderUnparsedTag($tag, $options);
		}

		$renderRule = $this->tags[$tagName];
		$rule = $this->rules->getTag($tagName);

		if ($rule && !empty($rule['stopSmilies']))
		{
			$options['stopSmilies']++;
		}
		if (!empty($renderRule['stopBreakConversion']))
		{
			$options['stopBreakConversion']++;
		}

		if (!empty($renderRule['callback']))
		{
			if (is_string($renderRule['callback']))
			{
				$renderRule['callback'] = [$this, $renderRule['callback']];
			}
			if (is_callable($renderRule['callback']))
			{
				$output = call_user_func($renderRule['callback'],
					$tag['children'], $tag['option'], $tag, $options, $this
				);
			}
			else
			{
				$output = $this->renderUnparsedTag($tag, $options);
			}
		}
		else if (!empty($renderRule['replace']))
		{
			$text = $this->renderSubTree($tag['children'], $options);
			if (empty($renderRule['keepEmpty']) && trim($text) === '')
			{
				return $text;
			}

			if ($tag['option'] !== null)
			{
				$optionOutput = $this->filterString($tag['option'], [
					'stopSmilies' => 1,
					'stopBreakConversion' => 1
					] + $options);
			}
			else
			{
				$optionOutput = '';
			}

			if (is_array($renderRule['replace']))
			{
				list($prepend, $append) = $renderRule['replace'];
				$output = $this->wrapHtml($prepend, $text, $append, $optionOutput);
			}
			else
			{
				$output = strtr($renderRule['replace'], [
					'{text}' => $text,
					'{option}' => $optionOutput
				]);
			}
		}
		else
		{
			$output = $this->renderUnparsedTag($tag, $options);
		}

		if ($rule && !empty($rule['stopSmilies']))
		{
			$options['stopSmilies']--;
		}
		if (!empty($renderRule['stopBreakConversion']))
		{
			$options['stopBreakConversion']--;
		}
		if (!empty($renderRule['trimAfter']))
		{
			$this->trimAfter = $renderRule['trimAfter'];
		}

		return $output;
	}

	public function renderString($string, array $options)
	{
		if ($this->trimAfter)
		{
			$string = preg_replace('#^([ \t]*\r?\n){1,' . $this->trimAfter . '}#i', '', $string);
			$this->trimAfter = 0;
		}

		return $this->filterString($string, $options);
	}

	public function filterString($string, array $options)
	{
		$string = $this->formatter->censorText($string);

		if (empty($options['stopSmilies']))
		{
			$string = $this->formatter->replaceSmiliesHtml($string);
		}
		else
		{
			$string = htmlspecialchars($string);
		}

		if (empty($options['stopBreakConversion']))
		{
			$string = nl2br($string);
		}

		return $string;
	}

	public function filterFinalOutput($output)
	{
		return '<div class="bbWrapper">' . trim($output) . '</div>';
	}

	public function wrapHtml($open, $inner, $close, $option = null)
	{
		if ($option !== null)
		{
			$open = sprintf($open, $option);
			$close = sprintf($close, $option);
		}

		return $open . $inner . $close;
	}

	protected function endsInBlockTag($text)
	{
		return preg_match('#</(p|div|blockquote|pre|ol|ul)>$#i', substr(rtrim($text), -20));
	}

	public function renderTagAlign(array $children, $option, array $tag, array $options)
	{
		$output = trim($this->renderSubTree($children, $options));

		$invisibleSpace = $this->endsInBlockTag($output) ? '' : '&#8203;';

		switch (strtolower($tag['tag']))
		{
			case 'left':
			case 'center':
			case 'right':
				return $this->wrapHtml(
					'<div style="text-align: ' . $tag['tag'] . '">',
					$output . $invisibleSpace,
					'</div>'
				);

			default:
				return $this->wrapHtml('<div>', $output . $invisibleSpace, '</div>');
		}
	}

	public function renderTagAttach(array $children, $option, array $tag, array $options)
	{
		$id = intval($this->renderSubTreePlain($children));
		if (!$id)
		{
			return '';
		}

		if (!empty($options['attachments'][$id]))
		{
			$attachment = $options['attachments'][$id];
		}
		else
		{
			$attachment = null;
		}

		$viewParams = [
			'id' => $id,
			'attachment' => $attachment,
			'canView' => !empty($options['viewAttachments']),
			'full' => ($option && strtolower($option) == 'full')
		];

		if (empty($options['lightbox']))
		{
			$viewParams['noLightbox'] = true;
		}

		return $this->templater->renderTemplate('public:bb_code_tag_attach', $viewParams);
	}

	public function renderTagCode(array $children, $option, array $tag, array $options)
	{
		$content = $this->renderSubTree($children, $options);
		// a bit like ltrim, but only remove blank lines, not leading tabs on the first line
		$content = preg_replace('#^([ \t]*\r?\n)+#', '', $content);
		$content = rtrim($content);

		/** @var \XF\Data\CodeLanguage $codeLanguages */
		$codeLanguages = \XF::app()->data('XF:CodeLanguage');
		$allowedLanguages = $codeLanguages->getSupportedLanguages(true);

		$language = strtolower(preg_replace('#[^a-z0-9_-]#i', '-', $option));
		if (isset($allowedLanguages[$language]))
		{
			$config = $allowedLanguages[$language];
		}
		else
		{
			$config = [];
			if ($language == 'rich')
			{
				$config['phrase'] = \XF::phrase('code_language.rich');
			}
			$language = '';
		}

		if (!is_array($this->allowedCodeLanguages))
		{
			$this->allowedCodeLanguages = preg_split('/\r?\n/', \XF::options()->allowedCodeLanguages);
		}


		if (!in_array($language, $this->allowedCodeLanguages))
		{
			$language = '';
		}

		return $this->getRenderedCode($content, $language, $config);
	}

	protected function getRenderedCode($content, $language, array $config = [])
	{
		return $this->templater->renderTemplate('public:bb_code_tag_code', [
			'content' => new \XF\PreEscaped($content),
			'language' => $language,
			'config' => $config
		]);
	}

	public function renderTagInlineCode(array $children, $option, array $tag, array $options)
	{
		$content = $this->renderSubTree($children, $options);
		$this->templater->includeCss('public:bb_code.less');
		return $this->wrapHtml('<code class="bbCodeInline">', $content, '</code>');
	}

	public function renderTagEmail(array $children, $option, array $tag, array $options)
	{
		if ($option !== null)
		{
			$email = $option;
			$text = $this->renderSubTree($children, $options);
		}
		else
		{
			$email = $this->renderSubTreePlain($children);
			$text = $this->filterString($email, $options);
		}

		if (strpos($email, '@') === false)
		{
			// invalid URL, ignore
			return $text;
		}

		$email = $this->formatter->censorText($email);

		return $this->wrapHtml(
			'<a href="mailto:' . htmlspecialchars($email) . '">',
			$text,
			'</a>'
		);
	}

	public function renderTagHtml(array $children, $option, array $tag, array $options)
	{
		return $this->renderTagCode($children, 'html', $tag, $options);
	}

	public function renderTagImage(array $children, $option, array $tag, array $options)
	{
		$url = $this->renderSubTreePlain($children);

		$validUrl = $this->getValidUrl($url);
		if (!$validUrl)
		{
			return $this->filterString($url, $options);
		}

		$censored = $this->formatter->censorText($validUrl);
		if ($censored != $validUrl)
		{
			return $this->filterString($url, $options);
		}

		if ($options['noProxy'])
		{
			$imageUrl = $validUrl;
		}
		else
		{
			$linkInfo = $this->formatter->getLinkClassTarget($validUrl);
			if ($linkInfo['local'])
			{
				$imageUrl = $validUrl;
			}
			else
			{
				$imageUrl = $this->formatter->getProxiedUrlIfActive('image', $validUrl);
				if (!$imageUrl)
				{
					$imageUrl = $validUrl;
				}
			}
		}

		if (!empty($options['lightbox']))
		{
			return $this->templater->renderTemplate('public:bb_code_tag_img', [
				'imageUrl' => $imageUrl,
				'validUrl' => $validUrl
			]);
		}
		else
		{
			return sprintf($this->imageTemplate,
				htmlspecialchars($imageUrl),
				htmlspecialchars($validUrl)
			);
		}
	}

	public function renderTagIndent(array $children, $option, array $tag, array $options)
	{
		$output = trim($this->renderSubTree($children, $options));

		$amount = $option ? intval($option) : 1;
		$amount = max(1, min($amount, 5));

		$invisibleSpace = $this->endsInBlockTag($output) ? '' : '&#8203;';

		$side = \XF::language()->isRtl() ? 'right' : 'left';
		return $this->wrapHtml(
			'<div style="margin-' . $side . ': ' . ($amount * 20) . 'px">',
			$output . $invisibleSpace,
			'</div>'
		);
	}

	public function renderTagList(array $children, $option, array $tag, array $options)
	{
		$listType = ($option && $option === '1' ? 'ol' : 'ul');
		$elements = [];
		$lastElement = '';

		foreach ($children AS $child)
		{
			if (is_array($child))
			{
				$childText = $this->renderTag($child, $options);
				if (preg_match('#^<(ul|ol)#', $childText))
				{
					$lastElement = rtrim($lastElement);
					if (substr($lastElement, -6) == '<br />')
					{
						$lastElement = substr($lastElement, 0, -6);
					}
				}
				$lastElement .= $childText;
			}
			else
			{
				if (strpos($child, '[*]') !== false)
				{
					$parts = explode('[*]', $child);

					$beforeFirst = array_shift($parts);
					if ($lastElement !== '' || trim($beforeFirst) !== '')
					{
						$lastElement .= $this->renderString($beforeFirst, $options);
					}

					foreach ($parts AS $part)
					{
						$this->appendListElement($elements, $lastElement);
						$lastElement = $this->renderString($part, $options);
					}
				}
				else
				{
					$lastElement .= $this->renderString($child, $options);
				}
			}
		}

		$this->appendListElement($elements, $lastElement);

		if (!$elements)
		{
			return '';
		}

		return $this->renderFinalListOutput($listType, $elements);
	}

	protected function appendListElement(array &$elements, $appendString)
	{
		if ($appendString !== '')
		{
			$appendString = rtrim($appendString);
			if (substr($appendString, -6) == '<br />')
			{
				$appendString = substr($appendString, 0, -6);
			}

			$elements[] = $appendString;
		}
	}

	protected function renderFinalListOutput($listType, array $elements)
	{
		$output = "<$listType>";
		foreach ($elements AS $element)
		{
			$output .= "\n<li data-xf-list-type=\"$listType\">$element</li>";
		}
		$output .= "\n</$listType>";

		return $output;
	}

	public function renderTagMedia(array $children, $option, array $tag, array $options)
	{
		$mediaKey = trim($this->renderSubTreePlain($children));
		if (preg_match('#[&?"\'<>\r\n]#', $mediaKey) || strpos($mediaKey, '..') !== false)
		{
			return '';
		}

		$censored = $this->formatter->censorText($mediaKey);
		if ($censored != $mediaKey)
		{
			return '';
		}

		$mediaSiteId = strtolower($option);
		if (!isset($this->mediaSites[$mediaSiteId]))
		{
			return '';
		}

		$site = $this->mediaSites[$mediaSiteId];

		$embedHtml = '';

		if (!empty($site['callback']) && is_callable($site['callback']))
		{
			$embedHtml = call_user_func($site['callback'], $mediaKey, $site, $mediaSiteId);
		}

		if (!$embedHtml)
		{
			$keyDigits = preg_replace('/[^0-9]/', '', $mediaKey);
			$keyEncoded = rawurlencode($mediaKey);
			$keySlash = str_replace('%2F', '/', $keyEncoded);

			if ($site['oembed_enabled'])
			{
				$embedHtml = $this->templater->renderTemplate('public:_media_site_embed_oembed', [
					'provider' => $mediaSiteId,
					'id' => $mediaKey,
					'site' => $site,
					'jsState' => $mediaSiteId,
					'url' => strtr($site['oembed_url_scheme'], [
						'{$id}' => $keyEncoded,
						'{$idSlash}' => $keySlash,
						'{$idDigits}' => $keyDigits
					])
				]);
			}
			else
			{
				$embedHtml = $this->templater->renderTemplate('public:_media_site_embed_' . $mediaSiteId, [
					'id'       => $keyEncoded,
					'idRaw'    => $mediaKey,
					'idSlash'  => $keySlash,
					'idDigits' => $keyDigits
				]);
			}
		}

		return $embedHtml;
	}

	public function renderTagPhp(array $children, $option, array $tag, array $options)
	{
		return $this->renderTagCode($children, 'php', $tag, $options);
	}

	public function renderTagQuote(array $children, $option, array $tag, array $options)
	{
		if (!$children)
		{
			return '';
		}

		$this->trimChildrenList($children);

		$content = $this->renderSubTree($children, $options);
		if ($content === '')
		{
			return '';
		}

		$name = null;
		$attributes = [];
		$source = [];

		if ($option !== null && strlen($option))
		{
			$parts = explode(',', $option);
			$name = $this->filterString(array_shift($parts), array_merge($options, [
				'stopSmilies' => 1,
				'stopBreakConversion' => 1
			]));

			foreach ($parts AS $part)
			{
				$attributeParts = explode(':', $part, 2);
				if (isset($attributeParts[1]))
				{
					$attrName = trim($attributeParts[0]);
					$attrValue = trim($attributeParts[1]);
					if ($attrName !== '' && $attrValue !== '')
					{
						$attributes[$attrName] = $attrValue;
					}
				}
			}

			if ($attributes)
			{
				$firstValue = reset($attributes);
				$firstName = key($attributes);
				if ($firstName != 'member')
				{
					$source = ['type' => $firstName, 'id' => intval($firstValue)];
				}
			}
		}

		return $this->getRenderedQuote($content, $name, $source, $attributes);
	}

	protected function getRenderedQuote($content, $name, array $source, array $attributes)
	{
		return $this->templater->renderTemplate('public:bb_code_tag_quote', [
			'content' => new \XF\PreEscaped($content),
			'name' => $name ? new \XF\PreEscaped($name) : null,
			'source' => $source,
			'attributes' => $attributes
		]);
	}

	public function renderTagFont(array $children, $option, array $tag, array $options)
	{
		$text = $this->renderSubTree($children, $options);
		if (trim($text) === '')
		{
			return $text;
		}

		$font = htmlspecialchars($option);

		if (strtolower(trim($font)) != 'inherit')
		{
			$font = "'{$font}'";
		}

		return $this->wrapHtml(
			'<span style="font-family: ' . $font . '">',
			$text,
			'</span>'
		);
	}

	public function renderTagSize(array $children, $option, array $tag, array $options)
	{
		$text = $this->renderSubTree($children, $options);
		if (trim($text) === '')
		{
			return $text;
		}

		$size = $this->getTextSize($option);
		if ($size)
		{
			return $this->wrapHtml(
				'<span style="font-size: ' . htmlspecialchars($size) . '">',
				$text,
				'</span>'
			);
		}
		else
		{
			return $text;
		}
	}

	protected function getTextSize($inputSize)
	{
		if (strval(intval($inputSize)) == strval($inputSize))
		{
			// int only, translate size
			if ($inputSize <= 0)
			{
				return null;
			}

			switch ($inputSize)
			{
				case 1: return '9px';
				case 2: return '10px';
				case 3: return '12px';
				case 4: return '15px';
				case 5: return '18px';
				case 6: return '22px';
				default: return '26px';
			}
		}
		else
		{
			// int and unit
			if (!preg_match('/^([0-9]+)px$/i', $inputSize, $match))
			{
				return null;
			}

			$size = intval($match[1]);
			$size = max(8, min($size, 36));

			return $size . 'px';
		}
	}

	public function renderTagSpoiler(array $children, $option, array $tag, array $options)
	{
		if (!$children)
		{
			return '';
		}

		$this->trimChildrenList($children);

		$content = $this->renderSubTree($children, $options);
		if ($content === '')
		{
			return '';
		}

		if ($option)
		{
			$title = $this->filterString($option, array_merge($options, [
				'stopSmilies' => 1,
				'stopBreakConversion' => 1
			]));
		}
		else
		{
			$title = null;
		}

		return $this->getRenderedSpoiler($content, $title);
	}

	protected function getRenderedSpoiler($content, $title = null)
	{
		return $this->templater->renderTemplate('public:bb_code_tag_spoiler', [
			'content' => new \XF\PreEscaped($content),
			'title' => $title ? new \XF\PreEscaped($title) : null
		]);
	}

	public function renderTagUrl(array $children, $option, array $tag, array $options)
	{
		if ($option !== null)
		{
			$url = $option;
			$text = $this->renderSubTree($children, $options);

			if ($text === '')
			{
				$text = $url;
			}
		}
		else
		{
			$url = $this->renderSubTreePlain($children);
			$text = rawurldecode($url);
			if (!preg_match('/./u', $text))
			{
				$text = $url;
			}
			$text = $this->formatter->censorText($text);

			if (!empty($options['shortenUrl']))
			{
				$length = utf8_strlen($text);
				if ($length > 100)
				{
					$text = utf8_substr_replace($text, '...', 35, $length - 35 - 45);
				}
			}

			$text = htmlspecialchars($text);
		}

		$url = $this->getValidUrl($url);
		if (!$url)
		{
			return $text;
		}

		$url = $this->formatter->censorText($url);

		return $this->getRenderedLink($text, $url, $options);
	}

	protected function getRenderedLink($text, $url, array $options)
	{
		$linkInfo = $this->formatter->getLinkClassTarget($url);
		$rels = [];

		$classAttr = $linkInfo['class'] ? " class=\"$linkInfo[class]\"" : '';
		$targetAttr = $linkInfo['target'] ? " target=\"$linkInfo[target]\"" : '';

		if (!$linkInfo['trusted'] && !empty($options['noFollowUrl']))
		{
			$rels[] = 'nofollow';
		}

		if ($linkInfo['target'])
		{
			$rels[] = 'noopener';
		}

		$proxyAttr = '';
		if (empty($options['noProxy']))
		{
			$proxyUrl = $this->formatter->getProxiedUrlIfActive('link', $url);
			if ($proxyUrl)
			{
				$proxyAttr = ' data-proxy-href="' . htmlspecialchars($proxyUrl) . '"';
			}
		}

		if ($rels)
		{
			$relAttr = ' rel="' . implode(' ', $rels) . '"';
		}
		else
		{
			$relAttr = '';
		}

		return $this->wrapHtml(
			'<a href="' . htmlspecialchars($url) . '"' . $targetAttr . $classAttr . $proxyAttr . $relAttr . '>',
			$text,
			'</a>'
		);
	}

	protected function getValidUrl($url)
	{
		$url = trim($url);

		if (preg_match('/^(\?|\/|#|:)/', $url))
		{
			return false;
		}
		if (strpos($url, "\n") !== false)
		{
			return false;
		}

		if (preg_match('#^(data|https?://data|javascript|about):#i', $url))
		{
			return false;
		}

		if (preg_match($this->allowedUrlProtocolRegex, $url))
		{
			return $url;
		}
		else
		{
			return 'http://' . $url;
		}
	}

	public function renderTagUser(array $children, $option, array $tag, array $options)
	{
		$content = $this->renderSubTree($children, $options);
		if ($content === '')
		{
			return '';
		}

		$userId = intval($option);
		if ($userId <= 0)
		{
			return $content;
		}

		$link = \XF::app()->router('public')->buildLink('full:members', ['user_id' => $userId]);

		return $this->wrapHtml(
			'<a href="' . htmlspecialchars($link) . '" class="username" data-xf-init="member-tooltip" data-user-id="' . $userId .  '" data-username="' . $content . '">',
			$content,
			'</a>'
		);
	}

	protected function trimChildrenList(array &$children)
	{
		$keys = array_keys($children);
		$firstKey = reset($keys);
		$lastKey = end($keys);

		if (is_string($children[$firstKey]))
		{
			$children[$firstKey] = ltrim($children[$firstKey]);
		}
		if (is_string($children[$lastKey]))
		{
			$children[$lastKey] = rtrim($children[$lastKey]);
		}
	}

	public static function factory(\XF\App $app)
	{
		$renderer = new static($app->stringFormatter(), $app->templater());
		$renderer->addMediaSites($app['bbCode.media']);

		return $renderer;
	}
}