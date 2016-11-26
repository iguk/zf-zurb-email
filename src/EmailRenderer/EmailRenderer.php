<?php

namespace Iguk\EmailRenderer;

use Hampe\Inky\Inky;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\CSSList\Document as CssDocument;
use Sabberworm\CSS\CSSList\AtRuleBlockList;
use Sabberworm\CSS\OutputFormat as CssOutputFormat;
use Zend\View\Renderer\PhpRenderer;

/**
 * Class EmailRenderer
 * @package Iguk\EmailRenderer
 */
class EmailRenderer {

    private $_layout;

    private $_phpRendererObject;

    /**
     * @param string $newLayoutPathString
     */
    public function setLayout($newLayoutPathString) {
        $this->_layout = $newLayoutPathString;
    }

    /**
     * @param PhpRenderer $phpRendererObject
     */
    public function setPhpRenderer(PhpRenderer $phpRendererObject) {
        $this->_phpRendererObject = $phpRendererObject;
    }

    public function render($nameOrModel, $values = null) {

        // render content
        $contentHtmlString = $this->_phpRendererObject->render($nameOrModel, $values);

        // inject rendered content into mail layout
        $templateHtmlString = $this->_phpRendererObject->render($this->_layout, array('contentString' => $contentHtmlString));

        // run Inky template engine
        $renderedHtmlString = (new Inky())->releaseTheKraken($templateHtmlString);

        // concatenate linked css files, remove corresponding "link" tags
        $cssString = '';
        $renderedDomDocument = new \DOMDocument();
        $renderedDomDocument->loadHtml($renderedHtmlString);
        /** @var \DOMNode $linkDomElement */
        foreach ($renderedDomDocument->getElementsByTagName('link') as $linkDomElement) {
            if ($linkDomElement->hasAttributes()) {
                $relAttribute = $linkDomElement->attributes->getNamedItem('rel');
                $typeAttribute = $linkDomElement->attributes->getNamedItem('type');
                $hrefAttribute = $linkDomElement->attributes->getNamedItem('href');
                if (
                    (
                        (!is_null($relAttribute) and ($relAttribute->nodeValue == 'stylesheet'))
                        or
                        (!is_null($typeAttribute) and ($typeAttribute->nodeValue == 'text/css'))
                    )
                    and
                    !is_null($hrefAttribute)
                    and
                    !empty($hrefAttribute->nodeValue)
                ) {
                    $cssString .= file_get_contents($hrefAttribute->nodeValue) . PHP_EOL;
                    $linkDomElement->parentNode->removeChild($linkDomElement);
                }
            }
        }
        $htmlWithoutCssString = $renderedDomDocument->saveHTML();

        // extract media queries from css
        $cssParser = new CssParser($cssString);
        $cssDocument = $cssParser->parse();
        $mediaQueriesCssList = new CssDocument();
        $usualRulesCssList = new CssDocument();
        foreach ($cssDocument->getContents() as $cssBlock) {
            if (($cssBlock instanceof AtRuleBlockList) and ('media' == $cssBlock->atRuleName())) {
                $mediaQueriesCssList->append($cssBlock);
            } else {
                $usualRulesCssList->append($cssBlock);
            }
        }

        // inline usual css rules into html
        $inlinedHtmlString = (new CssToInlineStyles($htmlWithoutCssString, $usualRulesCssList->render()))->convert();

        // inject media queries into body before content
        $mediaQueriesCssString = $mediaQueriesCssList->render(CssOutputFormat::createCompact());
        $htmlDomDocument = new \DOMDocument();
        $htmlDomDocument->loadHTML($inlinedHtmlString);
        $styleElement = $htmlDomDocument->createElement('style', $mediaQueriesCssString);
        $bodyNode = $htmlDomDocument->getElementsByTagName('body')->item(0);
        $bodyNode->insertBefore($styleElement, $bodyNode->firstChild);

        // return resulted html
        return $htmlDomDocument->saveHTML();
    }

} 