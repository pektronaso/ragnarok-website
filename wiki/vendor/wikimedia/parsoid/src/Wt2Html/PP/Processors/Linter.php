<?php

declare( strict_types = 1 );

namespace Wikimedia\Parsoid\Wt2Html\PP\Processors;

use stdClass;
use Wikimedia\Parsoid\Config\Env;
use Wikimedia\Parsoid\Config\WikitextConstants as Consts;
use Wikimedia\Parsoid\Core\DomSourceRange;
use Wikimedia\Parsoid\DOM\Element;
use Wikimedia\Parsoid\DOM\Node;
use Wikimedia\Parsoid\Ext\ParsoidExtensionAPI;
use Wikimedia\Parsoid\Utils\DOMCompat;
use Wikimedia\Parsoid\Utils\DOMDataUtils;
use Wikimedia\Parsoid\Utils\DOMUtils;
use Wikimedia\Parsoid\Utils\PHPUtils;
use Wikimedia\Parsoid\Utils\Utils;
use Wikimedia\Parsoid\Utils\WTUtils;
use Wikimedia\Parsoid\Wt2Html\Wt2HtmlDOMProcessor;

/**
 * DOM pass that walks the DOM tree, detects specific wikitext patterns,
 * and emits them as linter events.
 */
class Linter implements Wt2HtmlDOMProcessor {
	/** @var ParsoidExtensionAPI */
	private $extApi = null;

	/** @phan-var array<string,bool>|null */
	private $tagsWithChangedMisnestingBehavior = null;

	/** @var string|null */
	private $obsoleteTagsRE = null;

	/**
	 * We are trying to find HTML5 tags that have different behavior compared to HTML4
	 * in some misnesting scenarios around wikitext paragraphs.
	 *
	 * Ex: Input: <p><small>a</p><p>b</small></p>
	 *     Tidy  output: <p><small>a</small></p><p><small>b</small></p>
	 *     HTML5 output: <p><small>a</small></p><p><small>b</small></p>
	 *
	 * So, all good here.
	 * But, see how output changes when we use <span> instead
	 *
	 * Ex: Input: <p><span>a</p><p>b</span></p>
	 *     Tidy  output: <p><span>a</span></p><p><span>b</span></p>
	 *     HTML5 output: <p><span>a</span></p><p>b</p>
	 *
	 * The source wikitext is "<span>a\n\nb</span>". The difference persists even
	 * when you have "<span>a\n\n<div>b</div>" or "<span>a\n\n{|\n|x\n|}\nbar".
	 *
	 * This is because Tidy seems to be doing the equivalent of HTM5-treebuilder's
	 * active formatting element reconstruction step on all *inline* elements.
	 * However, HTML5 parsers only do that on formatting elements. So, we need
	 * to compute which HTML5 tags are subject to this differential behavior.
	 *
	 * We compute that by excluding the following tags from the list of all HTML5 tags
	 * - If our sanitizer doesn't allow them, they will be escaped => ignore them
	 * - HTML4 block tags are excluded (obviously)
	 * - Void tags don't matter since they cannot wrap anything (obviously)
	 * - Active formatting elements have special handling in the HTML5 tree building
	 *   algorithm where they are reconstructed to wrap all originally intended content.
	 *   (ex: <small> above)
	 *
	 * Here is the list of 22 HTML5 tags that are affected:
	 *    ABBR, BDI, BDO, CITE, DATA, DEL, DFN, INS, KBD, MARK,
	 *    Q, RB, RP, RT, RTC, RUBY, SAMP, SPAN, SUB, SUP, TIME, VAR
	 *
	 * https://phabricator.wikimedia.org/T176363#3628173 verifies that this list of
	 * tags all demonstrate this behavior.
	 *
	 * @return array
	 * @phan-return array<string,bool>
	 */
	private function getTagsWithChangedMisnestingBehavior(): array {
		if ( $this->tagsWithChangedMisnestingBehavior === null ) {
			// This set is frozen in time.  It gets us down to the requisite
			// 22 HTML5 tags above, but shouldn't be used for anything other
			// than that.
			$HTML4TidyBlockTags = PHPUtils::makeSet( [
				'div', 'p',
				# tables
				'table', 'tbody', 'thead', 'tfoot', 'caption', 'th', 'tr', 'td',
				# lists
				'ul', 'ol', 'li', 'dl', 'dt', 'dd',
				# HTML5 heading content
				'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hgroup',
				# HTML5 sectioning content
				'article', 'aside', 'nav', 'section', 'footer', 'header',
				'figure', 'figcaption', 'fieldset', 'details', 'blockquote',
				# other
				'hr', 'button', 'canvas', 'center', 'col', 'colgroup', 'embed',
				'map', 'object', 'pre', 'progress', 'video',
			] );
			$this->tagsWithChangedMisnestingBehavior = [];
			foreach ( Consts::$HTML['HTML5Tags'] as $tag => $dummy ) {
				if ( isset( Consts::$Sanitizer['AllowedLiteralTags'][$tag] ) &&
					!isset( $HTML4TidyBlockTags[$tag] ) &&
					!isset( Consts::$HTML['FormattingTags'][$tag] ) &&
					!isset( Consts::$HTML['VoidTags'][$tag] )
				) {
					$this->tagsWithChangedMisnestingBehavior[$tag] = true;
				}
			}
		}

		return $this->tagsWithChangedMisnestingBehavior;
	}

	/**
	 * Finds a matching node at the "start" of this node.
	 * @param ?Node $node
	 * @param Element $match
	 * @return ?Element
	 */
	private function leftMostMisnestedDescendent(
		?Node $node, Element $match
	): ?Element {
		if ( !$node instanceof Element ) {
			return null;
		}

		if ( DOMUtils::isMarkerMeta( $node, 'mw:Placeholder/StrippedTag' ) ) {
			$name = DOMDataUtils::getDataParsoid( $node )->name ?? null;
			return $name === DOMCompat::nodeName( $match ) ? $node : null;
		}

		if ( DOMCompat::nodeName( $node ) === DOMCompat::nodeName( $match ) ) {
			$dp = DOMDataUtils::getDataParsoid( $node );
			if ( ( DOMDataUtils::getDataParsoid( $match )->stx ?? null ) === ( $dp->stx ?? null ) &&
				!empty( $dp->autoInsertedStart )
			) {
				if ( !empty( $dp->autoInsertedEnd ) ) {
					return $this->getMatchingMisnestedNode( $node, $match );
				} else {
					return $node;
				}
			}
		}

		return $this->leftMostMisnestedDescendent( $node->firstChild, $match );
	}

	/**
	 * $node has an 'autoInsertedEnd' flag set on it. We are looking for
	 * its matching node that has an 'autoInsertedStart' flag set on it.
	 * This happens when the tree-builder fixes up misnested tags.
	 * This "adjacency" is wrt the HTML string. In a DOM, this can either
	 * be the next sibling OR, it might be the left-most-descendent of
	 * of $node's parent's sibling (and so on up the ancestor chain).
	 *
	 * @param Node $node
	 * @param Element $match
	 * @return Element|null
	 */
	private function getMatchingMisnestedNode( Node $node, Element $match ): ?Element {
		if ( DOMUtils::atTheTop( $node ) ) {
			return null;
		}

		if ( DOMUtils::nextNonSepSibling( $node ) ) {
			return $this->leftMostMisnestedDescendent( DOMUtils::nextNonSepSibling( $node ), $match );
		}

		return $this->getMatchingMisnestedNode( $node->parentNode, $match );
	}

	/**
	 * Given a tplInfo object, determine whether we are:
	 * - Not processing template content (could be extension or top level page)
	 * - Processing encapsulated content that is produced by a single template.
	 *   If so, return the name of that template.
	 * - Processing encapsulated content that comes from multiple templates.
	 *   If so, return a flag indicating this.
	 *
	 * FIXME: We might potentially be computing this information redundantly
	 * for every lint we find within this template's content. It could probably
	 * be cached in tplInfo after it is computed once.
	 *
	 * @param Env $env
	 * @param ?stdClass $tplInfo Template info.
	 * @return ?array
	 */
	private function findEnclosingTemplateName(
		Env $env, ?stdClass $tplInfo
	): ?array {
		if ( !$tplInfo ) {
			return null;
		}

		if ( !DOMUtils::hasTypeOf( $tplInfo->first, 'mw:Transclusion' ) ) {
			return null;
		}
		$dmw = DOMDataUtils::getDataMw( $tplInfo->first );
		if ( !empty( $dmw->parts ) && count( $dmw->parts ) === 1 ) {
			$p0 = $dmw->parts[0];
			$name = null;
			if ( !empty( $p0->template->target->href ) ) { // Could be "function"
				// PORT-FIXME: Should that be SiteConfig::relativeLinkPrefix() rather than './'?
				$name = PHPUtils::stripPrefix( $p0->template->target->href, './' );
			} elseif ( !empty( $p0->template ) ) {
				$name = trim( $p0->template->target->wt );
			} else {
				$name = trim( $p0->templatearg->target->wt );
			}
			return [ 'name' => $name ];
		} else {
			return [ 'multiPartTemplateBlock' => true ];
		}
	}

	/**
	 * Compute the DSR information for the lint object.
	 * - In the common case, this is simply the DSR value of the node
	 *   that generated the lint. But, occasionally, for some lints,
	 *   we might have to post-process the node's DSR.
	 * - If the lint is found in template content, then the DSR spans
	 *   the transclusion markup in the toplevel page source.
	 *
	 * @param ?array $tplLintInfo
	 * @param ?stdClass $tplInfo
	 * @param ?DomSourceRange $nodeDSR
	 * @param ?callable $updateNodeDSR
	 * @return ?DomSourceRange
	 */
	private function findLintDSR(
		?array $tplLintInfo, ?stdClass $tplInfo, ?DomSourceRange $nodeDSR,
		?callable $updateNodeDSR = null
	): ?DomSourceRange {
		if ( $tplLintInfo !== null || ( $tplInfo && !Utils::isValidDSR( $nodeDSR ) ) ) {
			return DOMDataUtils::getDataParsoid( $tplInfo->first )->dsr ?? null;
		} else {
			return $updateNodeDSR ? $updateNodeDSR( $nodeDSR ) : $nodeDSR;
		}
	}

	/**
	 * Determine if a node has an identical nested tag (?)
	 * @param Element $node
	 * @param string $name
	 * @return bool
	 */
	private function hasIdenticalNestedTag( Element $node, string $name ): bool {
		$c = $node->firstChild;
		while ( $c ) {
			if ( $c instanceof Element ) {
				if (
					DOMCompat::nodeName( $c ) === $name &&
					empty( DOMDataUtils::getDataParsoid( $c )->autoInsertedInd )
				) {
					return true;
				}

				return $this->hasIdenticalNestedTag( $c, $name );
			}

			$c = $c->nextSibling;
		}

		return false;
	}

	/**
	 * Determine if a node has misnestable content
	 * @param Node $node
	 * @param string $name
	 * @return bool
	 */
	private function hasMisnestableContent( Node $node, string $name ): bool {
		// For A, TD, TH, H* tags, Tidy doesn't seem to propagate
		// the unclosed tag outside these tags.
		// No need to check for tr/table since content cannot show up there
		if ( DOMUtils::atTheTop( $node ) || preg_match( '/^(?:a|td|th|h\d)$/D', DOMCompat::nodeName( $node ) ) ) {
			return false;
		}

		$next = DOMUtils::nextNonSepSibling( $node );
		if ( !$next ) {
			return $this->hasMisnestableContent( $node->parentNode, $name );
		}

		$contentNode = null;
		if ( DOMCompat::nodeName( $next ) === 'p' && !WTUtils::isLiteralHTMLNode( $next ) ) {
			$contentNode = DOMUtils::firstNonSepChild( $next );
		} else {
			$contentNode = $next;
		}

		// If the first "content" node we find is a matching
		// stripped tag, we have nothing that can get misnested
		return $contentNode && !(
			$contentNode instanceof Element &&
			DOMUtils::isMarkerMeta( $contentNode, 'mw:Placeholder/StrippedTag' ) &&
			isset( DOMDataUtils::getDataParsoid( $contentNode )->name ) &&
			DOMDataUtils::getDataParsoid( $contentNode )->name === $name
		);
	}

	/**
	 * Indicate whether an end tag is optional for this node
	 *
	 * See https://www.w3.org/TR/html5/syntax.html#optional-tags
	 *
	 * End tags for tr/td/th/li are entirely optional since they
	 * require a parent container and can only be followed by like
	 * kind.
	 *
	 * Caveat: <li>foo</li><ol>..</ol> and <li>foo<ol>..</ol>
	 * generate different DOM trees, so explicit </li> tag
	 * is required to specify which of the two was intended.
	 *
	 * With that one caveat around nesting, the parse with/without
	 * the end tag is identical. For now, ignoring that caveat
	 * since they aren't like to show up in our corpus much.
	 *
	 * For the other tags in that w3c spec section, I haven't reasoned
	 * through when exactly they are optional. Not handling that complexity
	 * for now since those are likely uncommon use cases in our corpus.
	 *
	 * @param Node $node
	 * @return bool
	 */
	private function endTagOptional( Node $node ): bool {
		static $tagNames = [ 'tr', 'td', 'th', 'li' ];
		return in_array( DOMCompat::nodeName( $node ), $tagNames, true );
	}

	/**
	 * Find the nearest ancestor heading tag
	 * @param Node $node
	 * @return Node|null
	 */
	private function getHeadingAncestor( Node $node ): ?Node {
		while ( $node && !preg_match( '/^h[1-6]$/D', DOMCompat::nodeName( $node ) ) ) {
			$node = $node->parentNode;
		}
		return $node;
	}

	/**
	 * For formatting tags, Tidy seems to be doing this "smart" fixup of
	 * unclosed tags by looking for matching unclosed pairs of identical tags
	 * and if the content ends in non-whitespace text, it treats the second
	 * unclosed opening tag as a closing tag. But, a HTML5 parser won't do this.
	 * So, detect this pattern and flag for linter fixup.
	 *
	 * @param Node $c
	 * @param stdClass $dp
	 * @return bool
	 */
	private function matchedOpenTagPairExists( Node $c, stdClass $dp ): bool {
		$lc = $c->lastChild;
		if ( !$lc instanceof Element || DOMCompat::nodeName( $lc ) !== DOMCompat::nodeName( $c ) ) {
			return false;
		}

		$lcDP = DOMDataUtils::getDataParsoid( $lc );
		if ( empty( $lcDP->autoInsertedEnd ) || ( $lcDP->stx ?? null ) !== ( $dp->stx ?? null ) ) {
			return false;
		}

		$prev = $lc->previousSibling;
		// PORT-FIXME: Do we care about non-ASCII whitespace here?
		if ( DOMUtils::isText( $prev ) && !preg_match( '/\s$/D', $prev->nodeValue ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Log Treebuilder fixups marked by dom.markTreeBuilderFixup.js
	 *
	 * It handles the following scenarios:
	 *
	 * 1. Unclosed end tags
	 * 2. Unclosed start tags
	 * 3. Stripped tags
	 *
	 * In addition, we have specialized categories for some patterns
	 * where we encounter unclosed end tags.
	 *
	 * 4. misnested-tag
	 * 5. html5-misnesting
	 * 6. multiple-unclosed-formatting-tags
	 * 7. unclosed-quotes-in-heading
	 *
	 * @param Env $env
	 * @param Element $c
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logTreeBuilderFixup(
		Env $env, Element $c, stdClass $dp, ?stdClass $tplInfo
	): void {
		// This might have been processed as part of
		// misnested-tag category identification.
		if ( !empty( $dp->tmp->linted ) ) {
			return;
		}

		$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
		// During DSR computation, stripped meta tags
		// surrender their width to its previous sibling.
		// We record the original DSR in the tmp attribute
		// for that reason.
		$dsr = $this->findLintDSR( $templateInfo, $tplInfo, $dp->tmp->origDSR ?? $dp->dsr ?? null );
		$lintObj = null;
		if ( DOMUtils::isMarkerMeta( $c, 'mw:Placeholder/StrippedTag' ) ) {
			$lintObj = [
				'dsr' => $dsr,
				'templateInfo' => $templateInfo,
				'params' => [ 'name' => $dp->name ?? null ],
			];
			$env->recordLint( 'stripped-tag', $lintObj );
		}

		// Dont bother linting for auto-inserted start/end or self-closing-tag if:
		// 1. c is a void element
		//    Void elements won't have auto-inserted start/end tags
		//    and self-closing versions are valid for them.
		//
		// 2. c is tbody (FIXME: don't remember why we have this exception)
		//
		// 3. c is not an HTML element (unless they are i/b quotes)
		//
		// 4. c doesn't have DSR info and doesn't come from a template either
		$cNodeName = DOMCompat::nodeName( $c );
		$ancestor = null;
		$isHtmlElement = WTUtils::hasLiteralHTMLMarker( $dp );
		if ( !Utils::isVoidElement( $cNodeName ) &&
			$cNodeName !== 'tbody' &&
			( $isHtmlElement || DOMUtils::isQuoteElt( $c ) ) &&
			( $tplInfo !== null || $dsr !== null )
		) {
			if ( !empty( $dp->selfClose ) && $cNodeName !== 'meta' ) {
				$lintObj = [
					'dsr' => $dsr,
					'templateInfo' => $templateInfo,
					'params' => [ 'name' => $cNodeName ],
				];
				$env->recordLint( 'self-closed-tag', $lintObj );
				// The other checks won't pass - no need to test them.
				return;
			}

			if (
				( $dp->autoInsertedEnd ?? null ) === true &&
				( $tplInfo || ( $dsr->openWidth ?? 0 ) > 0 )
			) {
				$lintObj = [
					'dsr' => $dsr,
					'templateInfo' => $templateInfo,
					'params' => [ 'name' => $cNodeName ],
				];

				// FIXME: This literal html marker check is strictly not required
				// (a) we've already checked that above and know that isQuoteElt is
				//     not one of our tags.
				// (b) none of the tags in the list have native wikitext syntax =>
				//     they will show up as literal html tags.
				// But, in the interest of long-term maintenance in the face of
				// changes (to wikitext or html specs), let us make it explicit.
				if ( $isHtmlElement &&
					isset( $this->getTagsWithChangedMisnestingBehavior()[DOMCompat::nodeName( $c )] ) &&
					$this->hasMisnestableContent( $c, DOMCompat::nodeName( $c ) ) &&
					// Tidy WTF moment here!
					// I don't know why Tidy does something very different
					// when there is an identical nested tag here.
					//
					// <p><span id='1'>a<span>X</span></p><p>b</span></p>
					// vs.
					// <p><span id='1'>a</p><p>b</span></p>  OR
					// <p><span id='1'>a<del>X</del></p><p>b</span></p>
					//
					// For the first snippet, Tidy only wraps "a" with the id='1' span
					// For the second and third snippets, Tidy wraps "b" with the id='1' span as well.
					//
					// For the corresponding wikitext that generates the above token stream,
					// Parsoid (and Remex) won't wrap 'b' with the id=1' span at all.
					!$this->hasIdenticalNestedTag( $c, DOMCompat::nodeName( $c ) )
				) {
					$env->recordLint( 'html5-misnesting', $lintObj );
				// phpcs:ignore MediaWiki.ControlStructures.AssignmentInControlStructures.AssignmentInControlStructures
				} elseif ( !$isHtmlElement && DOMUtils::isQuoteElt( $c ) &&
					( $ancestor = $this->getHeadingAncestor( $c->parentNode ) )
				) {
					$lintObj['params']['ancestorName'] = DOMCompat::nodeName( $ancestor );
					$env->recordLint( 'unclosed-quotes-in-heading', $lintObj );
				} else {
					$adjNode = $this->getMatchingMisnestedNode( $c, $c );
					if ( $adjNode ) {
						$adjDp = DOMDataUtils::getDataParsoid( $adjNode );
						if ( !isset( $adjDp->tmp ) ) {
							$adjDp->tmp = new stdClass;
						}
						$adjDp->tmp->linted = true;
						$env->recordLint( 'misnested-tag', $lintObj );
					} elseif ( !$this->endTagOptional( $c ) && empty( $dp->autoInsertedStart ) ) {
						$lintObj['params']['inTable'] = DOMUtils::hasNameOrHasAncestorOfName( $c, 'table' );
						$env->recordLint( 'missing-end-tag', $lintObj );
						if ( isset( Consts::$HTML['FormattingTags'][DOMCompat::nodeName( $c )] ) &&
							$this->matchedOpenTagPairExists( $c, $dp )
						) {
							$env->recordLint( 'multiple-unclosed-formatting-tags', $lintObj );
						}
					}
				}
			}
		}
	}

	/**
	 * Log fostered content marked by markFosteredContent.js
	 *
	 * This will log cases like:
	 *
	 * {|
	 * foo
	 * |-
	 * | bar
	 * |}
	 *
	 * Here 'foo' gets fostered out.
	 *
	 * @param Env $env
	 * @param Element $node
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 * @return ?Element
	 */
	private function logFosteredContent(
		Env $env, Element $node, stdClass $dp, ?stdClass $tplInfo
	): ?Element {
		$maybeTable = $node->nextSibling;
		$clear = false;

		while ( $maybeTable && DOMCompat::nodeName( $maybeTable ) !== 'table' ) {
			if ( $tplInfo && $maybeTable === $tplInfo->last ) {
				$clear = true;
			}
			$maybeTable = $maybeTable->nextSibling;
		}

		if ( !$maybeTable instanceof Element ) {
			return null;
		} elseif ( $clear && $tplInfo ) {
			$tplInfo->clear = true;
		}

		// In pathological cases, we might walk past fostered nodes
		// that carry templating information. This then triggers
		// other errors downstream. So, walk back to that first node
		// and ignore this fostered content error. The new node will
		// trigger fostered content lint error.
		if ( !$tplInfo && WTUtils::hasParsoidAboutId( $maybeTable ) &&
			!WTUtils::isFirstEncapsulationWrapperNode( $maybeTable )
		) {
			$tplNode = WTUtils::findFirstEncapsulationWrapperNode( $maybeTable );
			if ( $tplNode !== null ) {
				return $tplNode;
			}

			// We got misled by the about id on 'maybeTable'.
			// Let us carry on with regularly scheduled programming.
		}

		$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
		$lintObj = [
			'dsr' => $this->findLintDSR(
				$templateInfo, $tplInfo, DOMDataUtils::getDataParsoid( $maybeTable )->dsr ?? null
			),
			'templateInfo' => $templateInfo,
		];
		$env->recordLint( 'fostered', $lintObj );

		return $maybeTable;
	}

	/**
	 * Log obsolete HTML tags
	 * @param Env $env
	 * @param Element $c
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logObsoleteHTMLTags(
		Env $env, Element $c, stdClass $dp, ?stdClass $tplInfo
	): void {
		if ( !$this->obsoleteTagsRE ) {
			$elts = [];
			foreach ( Consts::$HTML['OlderHTMLTags'] as $tag => $dummy ) {
				// Looks like all existing editors let editors add the <big> tag.
				// VE has a button to add <big>, it seems so does the WikiEditor
				// and JS wikitext editor. So, don't flag BIG as an obsolete tag.
				if ( $tag !== 'big' ) {
					$elts[] = preg_quote( $tag, '/' );
				}
			}
			$this->obsoleteTagsRE = '/^(?:' . implode( '|', $elts ) . ')$/D';
		}

		$templateInfo = null;
		if ( ( empty( $dp->autoInsertedStart ) || empty( $dp->autoInsertedEnd ) ) &&
			preg_match( $this->obsoleteTagsRE, DOMCompat::nodeName( $c ) )
		) {
			$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
			$lintObj = [
				'dsr' => $this->findLintDSR( $templateInfo, $tplInfo, $dp->dsr ?? null ),
				'templateInfo' => $templateInfo,
				'params' => [ 'name' => DOMCompat::nodeName( $c ) ],
			];
			$env->recordLint( 'obsolete-tag', $lintObj );
		}

		if ( DOMCompat::nodeName( $c ) === 'font' && $c->hasAttribute( 'color' ) ) {
			/* ----------------------------------------------------------
			 * Tidy migrates <font> into the link in these cases
			 *     <font>[[Foo]]</font>
			 *     <font>[[Foo]]l</font> (link-trail)
			 *     <font><!--boo-->[[Foo]]</font>
			 *     <font>__NOTOC__[[Foo]]</font>
			 *     <font>[[Category:Foo]][[Foo]]</font>
			 *     <font>{{1x|[[Foo]]}}</font>
			 *
			 * Tidy does not migrate <font> into the link in these cases
			 *     <font> [[Foo]]</font>
			 *     <font>[[Foo]] </font>
			 *     <font>[[Foo]]L</font> (not a link-trail)
			 *     <font>[[Foo]][[Bar]]</font>
			 *     <font>[[Foo]][[Bar]]</font>
			 *
			 * <font> is special.
			 * This behavior is not seen with other formatting tags.
			 *
			 * Remex/parsoid won't do any of this.
			 * This difference in behavior only matters when the font tag
			 * specifies a link colour because the link no longer renders
			 * as blue/red but in the font-specified colour.
			 * ---------------------------------------------------------- */
			$tidyFontBug = $c->firstChild !== null;
			$haveLink = false;
			for ( $n = $c->firstChild;  $n;  $n = $n->nextSibling ) {
				if ( DOMCompat::nodeName( $n ) !== 'a' &&
					!WTUtils::isRenderingTransparentNode( $n ) &&
					!WTUtils::isTplMarkerMeta( $n )
				) {
					$tidyFontBug = false;
					break;
				}

				if ( DOMCompat::nodeName( $n ) === 'a' || DOMCompat::nodeName( $n ) === 'figure' ) {
					if ( !$haveLink ) {
						$haveLink = true;
					} else {
						$tidyFontBug = false;
						break;
					}
				}
			}

			if ( $tidyFontBug ) {
				$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
				$env->recordLint( 'tidy-font-bug', [
					'dsr' => $this->findLintDSR( $templateInfo, $tplInfo, $dp->dsr ?? null ),
					'templateInfo' => $templateInfo,
					'params' => [ 'name' => 'font' ]
				] );
			}
		}
	}

	/**
	 * Log bogus (=unrecognized) media options
	 *
	 * See - https://www.mediawiki.org/wiki/Help:Images#Syntax
	 *
	 * @param Env $env
	 * @param Node $c
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logBogusMediaOptions(
		Env $env, Node $c, stdClass $dp, ?stdClass $tplInfo
	): void {
		if ( WTUtils::isGeneratedFigure( $c ) && !empty( $dp->optList ) ) {
			$items = [];
			foreach ( $dp->optList as $item ) {
				if ( $item['ck'] === 'bogus' ) {
					$items[] = $item['ak'];
				}
			}
			if ( $items ) {
				$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
				$env->recordLint( 'bogus-image-options', [
					'dsr' => $this->findLintDSR( $templateInfo, $tplInfo, $dp->dsr ?? null ),
					'templateInfo' => $templateInfo,
					'params' => [ 'items' => $items ]
				] );
			}
		}
	}

	/**
	 * Log tables Tidy deletes
	 *
	 * In this example below, the second table is in a fosterable position
	 * (inside a <tr>). The tree builder closes the first table at that point
	 * and starts a new table there. We are detecting this pattern because
	 * Tidy does something very different here. It strips the inner table
	 * and retains the outer table. So, for preserving rendering of pages
	 * that are tailored for Tidy, editors have to fix up this wikitext
	 * to strip the inner table (to mimic what Tidy does).
	 *
	 *   {| style='border:1px solid red;'
	 *   |a
	 *   |-
	 *   {| style='border:1px solid blue;'
	 *   |b
	 *   |c
	 *   |}
	 *   |}
	 *
	 * @param Env $env
	 * @param Node $c
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logDeletableTables(
		Env $env, Node $c, stdClass $dp, ?stdClass $tplInfo
	): void {
		if ( DOMCompat::nodeName( $c ) === 'table' ) {
			$prev = DOMUtils::previousNonSepSibling( $c );
			if ( $prev instanceof Element && DOMCompat::nodeName( $prev ) === 'table' &&
				!empty( DOMDataUtils::getDataParsoid( $prev )->autoInsertedEnd )
			) {
				$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
				$dsr = $this->findLintDSR(
					$templateInfo,
					$tplInfo,
					$dp->dsr ?? null,
					static function ( ?DomSourceRange $nodeDSR ): ?DomSourceRange {
						// Identify the dsr-span of the opening tag
						// of the table that needs to be deleted
						$x = $nodeDSR === null ? null : ( clone $nodeDSR );
						if ( !empty( $x->openWidth ) ) {
							$x->end = $x->innerStart();
							$x->openWidth = 0;
							$x->closeWidth = 0;
						}
						return $x;
					}
				);
				$lintObj = [
					'dsr' => $dsr,
					'templateInfo' => $templateInfo,
					'params' => [ 'name' => 'table' ],
				];
				$env->recordLint( 'deletable-table-tag', $lintObj );
			}
		}
	}

	/**
	 * Find the first child passing the filter.
	 * @param Node $node
	 * @param callable $filter
	 * @return Node|null
	 */
	private function findMatchingChild( Node $node, callable $filter ): ?Node {
		$c = $node->firstChild;
		while ( $c && !$filter( $c ) ) {
			$c = $c->nextSibling;
		}

		return $c;
	}

	/**
	 * Test if the node has a 'nowrap' CSS rule
	 *
	 * In the general case, this CSS can come from a class,
	 * or from a <style> tag or a stylesheet or even from JS code.
	 * But, for now, we are restricting this inspection to inline CSS
	 * since the intent is to aid editors in fixing patterns that
	 * can be automatically detected.
	 *
	 * Special case for enwiki that has Template:nowrap which
	 * assigns class='nowrap' with CSS white-space:nowrap in
	 * MediaWiki:Common.css
	 *
	 * @param Node $node
	 * @return bool
	 */
	private function hasNoWrapCSS( Node $node ): bool {
		return $node instanceof Element && (
			str_contains( $node->getAttribute( 'style' ) ?? '', 'nowrap' ) ||
			preg_match( '/(?:^|\s)nowrap(?:$|\s)/D', $node->getAttribute( 'class' ) ?? '' )
		);
	}

	/**
	 * Log bad P wrapping
	 *
	 * @param Env $env
	 * @param Element $node
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logBadPWrapping(
		Env $env, Element $node, stdClass $dp, ?stdClass $tplInfo
	): void {
		if (
			!DOMUtils::isWikitextBlockNode( $node ) &&
			DOMUtils::isWikitextBlockNode( $node->parentNode ) &&
			$this->hasNoWrapCSS( $node )
		) {
			$p = $this->findMatchingChild( $node, static function ( $e ) {
				return DOMCompat::nodeName( $e ) === 'p';
			} );
			if ( $p ) {
				$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
				$lintObj = [
					'dsr' => $this->findLintDSR( $templateInfo, $tplInfo, $dp->dsr ?? null ),
					'templateInfo' => $templateInfo,
					'params' => [
						'root' => DOMCompat::nodeName( $node->parentNode ),
						'child' => DOMCompat::nodeName( $node ),
					]
				];
				$env->recordLint( 'pwrap-bug-workaround', $lintObj );
			}
		}
	}

	/**
	 * Log Tidy div span flip
	 * @param Env $env
	 * @param Element $node
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logTidyDivSpanFlip(
		Env $env, Element $node, stdClass $dp, ?stdClass $tplInfo
	): void {
		if ( DOMCompat::nodeName( $node ) !== 'span' ) {
			return;
		}

		$fc = DOMUtils::firstNonSepChild( $node );
		if ( !$fc instanceof Element || DOMCompat::nodeName( $fc ) !== 'div' ) {
			return;
		}

		// No style/class attributes -- so, this won't affect rendering
		if ( !$node->hasAttribute( 'class' ) && !$node->hasAttribute( 'style' ) &&
			!$fc->hasAttribute( 'class' ) && !$fc->hasAttribute( 'style' )
		) {
			return;
		}

		$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
		$lintObj = [
			'dsr' => $this->findLintDSR( $templateInfo, $tplInfo, $dp->dsr ?? null ),
			'templateInfo' => $templateInfo,
			'params' => [ 'subtype' => 'div-span-flip' ]
		];
		$env->recordLint( 'misc-tidy-replacement-issues', $lintObj );
	}

	/**
	 * Log tidy whitespace bug
	 * @param Env $env
	 * @param Node $node
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logTidyWhitespaceBug(
		Env $env, Node $node, stdClass $dp, ?stdClass $tplInfo
	): void {
		// We handle a run of nodes in one shot.
		// No need to reprocess repeatedly.
		if ( !empty( $dp->tmp->processedTidyWSBug ) ) {
			return;
		}

		// Find the longest run of nodes that are affected by white-space:nowrap CSS
		// in a way that leads to unsightly rendering in HTML5 compliant browsers.
		//
		// Check if Tidy does buggy whitespace hoisting there to provide the browser
		// opportunities to split the content in short segments.
		//
		// If so, editors would need to edit this run of nodes to introduce
		// whitespace breaks as necessary so that HTML5 browsers get that
		// same opportunity when Tidy is removed.
		$s = null;
		$nowrapNodes = [];
		'@phan-var array<array{node:Node,tidybug:bool,hasLeadingWS:bool}> $nowrapNodes';
		$startNode = $node;
		$haveTidyBug = false;
		$runLength = 0;

		// <br>, <wbr>, <hr> break a line
		while ( $node && !DOMUtils::isRemexBlockNode( $node ) &&
			!in_array( DOMCompat::nodeName( $node ), [ 'hr', 'br', 'wbr' ], true )
		) {
			if ( DOMUtils::isText( $node ) || !$this->hasNoWrapCSS( $node ) ) {
				// No CSS property that affects whitespace.
				$s = $node->textContent;
				if ( preg_match( '/^([^\s]*)\s/', $s, $m ) ) { // PORT-FIXME: non-ASCII whitespace?
					$runLength += strlen( $m[1] );
					$nowrapNodes[] = [
						'node' => $node,
						'tidybug' => false,
						'hasLeadingWS' => ( preg_match( '/^\s/', $s ) === 1 ), // PORT-FIXME: non-ASCII whitespace?
					];
					break;
				} else {
					$nowrapNodes[] = [ 'node' => $node, 'tidybug' => false ];
					$runLength += strlen( $s );
				}
			} else {
				// Find last non-comment child of node
				$last = $node->lastChild;
				while ( $last && DOMUtils::isComment( $last ) ) {
					$last = $last->previousSibling;
				}

				$bug = false;
				if ( $last && DOMUtils::isText( $last ) &&
					preg_match( '/\s$/D', $last->nodeValue ) // PORT-FIXME: non-ASCII whitespace?
				) {
					// In this scenario, when Tidy hoists the whitespace to
					// after the node, that whitespace is not subject to the
					// nowrap CSS => browsers can break content there.
					//
					// But, non-Tidy libraries won't hoist the whitespace.
					// So, browsers don't have a place to break content.
					$bug = true;
					$haveTidyBug = true;
				}

				$nowrapNodes[] = [ 'node' => $node, 'tidybug' => $bug ];
				$runLength += strlen( $node->textContent );
			}

			// Don't cross template boundaries at the top-level
			if ( $tplInfo && $tplInfo->last === $node ) {
				// Exiting a top-level template
				break;
			} elseif ( !$tplInfo && WTUtils::findFirstEncapsulationWrapperNode( $node ) ) {
				// Entering a top-level template
				break;
			}

			// Move to the next non-comment sibling
			$node = $node->nextSibling;
			while ( $node && DOMUtils::isComment( $node ) ) {
				$node = $node->nextSibling;
			}
		}

		$markProcessedNodes = static function () use ( &$nowrapNodes ) { // Helper
			foreach ( $nowrapNodes as $o ) {
				// Phan fails at applying the instanceof type restriction to the array member when analyzing the
				// following call, but is fine when it's copied to a local variable.
				$stupidPhan = $o['node'];
				if ( $stupidPhan instanceof Element ) {
					DOMDataUtils::getDataParsoid( $stupidPhan )->tmp->processedTidyWSBug = true;
				}
			}
		};

		if ( !$haveTidyBug ) {
			// Mark processed nodes and bail
			$markProcessedNodes();
			return;
		}

		// Find run before startNode that doesn't have a whitespace break
		$prev = $startNode->previousSibling;
		while ( $prev && !DOMUtils::isRemexBlockNode( $prev ) ) {
			if ( !DOMUtils::isComment( $prev ) ) {
				$s = $prev->textContent;
				// Find the last \s in the string
				if ( preg_match( '/\s([^\s]*)$/D', $s, $m ) ) { // PORT-FIXME: non-ASCII whitespace here?
					$runLength += strlen( $m[1] );
					break;
				} else {
					$runLength += strlen( $s );
				}
			}
			$prev = $prev->previousSibling;
		}

		if ( $runLength < $env->getSiteConfig()->tidyWhitespaceBugMaxLength() ) {
			// Mark processed nodes and bail
			$markProcessedNodes();
			return;
		}

		// For every node where Tidy hoists whitespace,
		// emit an event to flag a whitespace fixup opportunity.
		$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
		$n = count( $nowrapNodes ) - 1;
		foreach ( $nowrapNodes as $i => $o ) {
			if ( $o['tidybug'] && $i < $n && empty( $nowrapNodes[$i + 1]['hasLeadingWS'] ) ) {
				$stupidPhan = $o['node']; // (see above)
				$lintObj = [
					'dsr' => $this->findLintDSR(
						$templateInfo,
						$tplInfo,
						$stupidPhan instanceof Element
							? DOMDataUtils::getDataParsoid( $stupidPhan )->dsr ?? null
							: null
					),
					'templateInfo' => $templateInfo,
					'params' => [
						'node' => DOMCompat::nodeName( $o['node'] ),
						'sibling' => DOMCompat::nodeName( $o['node']->nextSibling )
					]
				];

				$env->recordLint( 'tidy-whitespace-bug', $lintObj );
			}
		}

		$markProcessedNodes();
	}

	/**
	 * Detect multiple-unclosed-formatting-tags errors.
	 *
	 * Since unclosed <small> and <big> tags accumulate their effects
	 * in HTML5 parsers (unlike in Tidy where it seems to suppress
	 * multiple unclosed elements of the same name), such pages will
	 * break pretty spectacularly with Remex.
	 *
	 * Ex: https://it.wikipedia.org/wiki/Hubert_H._Humphrey_Metrodome?oldid=93017491#Note
	 *
	 * @param array $lints
	 * @param Env $env
	 */
	private function detectMultipleUnclosedFormattingTags( array $lints, Env $env ): void {
		$firstUnclosedTag = [
			'small' => null,
			'big' => null
		];
		$multiUnclosedTagName = null;
		foreach ( $lints as $item ) {
			// Unclosed tags in tables don't leak out of the table
			if ( $item['type'] === 'missing-end-tag' && !$item['params']['inTable'] ) {
				if ( $item['params']['name'] === 'small' || $item['params']['name'] === 'big' ) {
					$tagName = $item['params']['name'];
					// @phan-suppress-next-line PhanPossiblyUndeclaredVariable
					if ( !$firstUnclosedTag[$tagName] ) {
						$firstUnclosedTag[$tagName] = $item;
					} else {
						$multiUnclosedTagName = $tagName;
						break;
					}
				}
			}
		}

		if ( $multiUnclosedTagName ) {
			$item = $firstUnclosedTag[$multiUnclosedTagName];
			if ( isset( $item['dsr'] ) ) {
				$item['dsr'] = DomSourceRange::fromArray( $item['dsr'] );
			}
			$env->recordLint( 'multiple-unclosed-formatting-tags', [
				'params' => $item['params'] ?? [],
				'dsr' => $item['dsr'] ?? null,
				'templateInfo' => $item['templateInfo'] ?? null
			] );
		}
	}

	/**
	 * Post-process an array of lints
	 * @param array $lints
	 * @param Env $env
	 */
	private function postProcessLints( array $lints, Env $env ): void {
		$this->detectMultipleUnclosedFormattingTags( $lints, $env );
	}

	/**
	 * Get wikitext list item ancestor
	 * @param ?Node $node
	 * @return ?Node
	 */
	private function getWikitextListItemAncestor( ?Node $node ): ?Node {
		while ( $node && !DOMUtils::isListItem( $node ) ) {
			$node = $node->parentNode;
		}

		if ( $node && !WTUtils::isLiteralHTMLNode( $node ) &&
			!WTUtils::fromExtensionContent( $node, 'references' )
		) {
			return $node;
		}

		return null;
	}

	/**
	 * Log PHP parser bug
	 * @param Env $env
	 * @param Element $node
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logPHPParserBug(
		Env $env, Element $node, stdClass $dp, ?stdClass $tplInfo
	): void {
		$li = null;
		// phpcs:ignore MediaWiki.ControlStructures.AssignmentInControlStructures.AssignmentInControlStructures
		if ( !WTUtils::isLiteralHTMLNode( $node ) ||
			DOMCompat::nodeName( $node ) !== 'table' ||
			!( $li = $this->getWikitextListItemAncestor( $node ) ) ||
			!str_contains( DOMCompat::getOuterHTML( $node ), "\n" )
		) {
			return;
		}

		// We have an HTML table nested inside a list
		// that has a newline break in its outer HTML
		// => we are in trouble with the PHP Parser + Remex combo
		$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
		$lintObj = [
			'dsr' => $this->findLintDSR(
				$templateInfo, $tplInfo, DOMDataUtils::getDataParsoid( $node )->dsr ?? null
			),
			'templateInfo' => $templateInfo,
			'params' => [
				'name' => 'table',
				'ancestorName' => DOMCompat::nodeName( $li ),
			],
		];
		$env->recordLint( 'multiline-html-table-in-list', $lintObj );
	}

	/**
	 * Log wikilinks or media in external links
	 *
	 * HTML tags can be nested but this is not the case for <a> tags
	 * which when nested outputs the <a> tags adjacent to each other
	 * In the example below, [[Google]] is a wikilink that is nested
	 * in the outer external link
	 * [http://google.com This is [[Google]]'s search page]
	 *
	 * @param Env $env
	 * @param Element $c
	 * @param stdClass $dp
	 * @param ?stdClass $tplInfo
	 */
	private function logWikilinksInExtlinks(
		Env $env, Element $c, stdClass $dp, ?stdClass $tplInfo
	) {
		if ( DOMCompat::nodeName( $c ) === 'a' && $c->getAttribute( 'rel' ) === 'mw:ExtLink' ) {
			$lintError = false;
			$element = $c->nextSibling;
			while ( $element instanceof Element ) {
				if (
					DOMCompat::nodeName( $element ) === 'a' &&
					( DOMDataUtils::getDataParsoid( $element )->misnested ?? false ) &&
					(
						$element->getAttribute( 'rel' ) === 'mw:WikiLink' ||
						// Media structure gets blown apart when nested in an
						// external link so testing by typeof is not very
						// useful.  We'll just have to assume the img tag here
						// came from media syntax.
						( $element->firstChild instanceof Element &&
							DOMCompat::nodeName( $element->firstChild ) === 'img' )
					)
				) {
					$lintError = true;
					break;
				}
				$element = $element->firstChild;
			}
			// Media as opposed to most instances of img (barring the link= trick), don't result
			// in misnesting according the html5 spec since we're actively suppressing links in
			// their structure. However, since timed media is inherently clickable, being nested
			// in an extlink could surprise a user clicking on it by navigating away from the page.
			if ( !$lintError ) {
				DOMUtils::visitDOM( $c, static function ( $element ) use ( &$lintError ) {
					if ( $element instanceof Element &&
						( DOMCompat::nodeName( $element ) === 'audio' ||
							DOMCompat::nodeName( $element ) === 'video' )
					) {
						$lintError = true;
					}
				} );
			}
			if ( $lintError ) {
				$templateInfo = $this->findEnclosingTemplateName( $env, $tplInfo );
				$lintObj = [
					'dsr' => $this->findLintDSR(
						$templateInfo, $tplInfo, DOMDataUtils::getDataParsoid( $c )->dsr ?? null
					),
					'templateInfo' => $templateInfo,
				];
				$env->recordLint( 'wikilink-in-extlink', $lintObj );
			}
		}
	}

	/**
	 * Log wikitext fixups
	 * @param Element $node
	 * @param Env $env
	 * @param ?stdClass $tplInfo
	 * @return ?Element
	 */
	private function logWikitextFixups(
		Element $node, Env $env, ?stdClass $tplInfo
	): ?Element {
		$dp = DOMDataUtils::getDataParsoid( $node );

		$this->logTreeBuilderFixup( $env, $node, $dp, $tplInfo );
		$this->logDeletableTables( $env, $node, $dp, $tplInfo ); // For T161341
		$this->logBadPWrapping( $env, $node, $dp, $tplInfo ); // For T161306
		$this->logObsoleteHTMLTags( $env, $node, $dp, $tplInfo );
		$this->logBogusMediaOptions( $env, $node, $dp, $tplInfo );
		$this->logTidyWhitespaceBug( $env, $node, $dp, $tplInfo );
		$this->logTidyDivSpanFlip( $env, $node, $dp, $tplInfo );

		// When an HTML table is nested inside a list and if any part of the table
		// is on a new line, the PHP parser misnests the list and the table.
		// Tidy fixes the misnesting one way (puts table inside/outside the list)
		// HTML5 parser fix it another way (list expands to rest of the page!)
		$this->logPHPParserBug( $env, $node, $dp, $tplInfo );
		$this->logWikilinksInExtlinks( $env, $node, $dp, $tplInfo );

		// Log fostered content, but skip rendering-transparent nodes
		if ( !empty( $dp->fostered ) && !WTUtils::isRenderingTransparentNode( $node ) ) {
			return $this->logFosteredContent( $env, $node, $dp, $tplInfo );
		} else {
			return null;
		}
	}

	/**
	 * Walk the DOM and compute lints for the entire tree.
	 * - When we enter encapsulated content (templates or extensions),
	 *   compute "tplInfo" (misnamed given that it can be an extension)
	 *   so that lints from the templates' content can be mapped back
	 *   to the transclusion that generated them.
	 * - When we process extensions, if we have a lint handler for the
	 *   extension, let the extension's lint handler compute lints.
	 *
	 * @param Node $root
	 * @param Env $env
	 * @param ?stdClass $tplInfo
	 */
	private function findLints(
		Node $root, Env $env, ?stdClass $tplInfo = null
	): void {
		$node = $root->firstChild;
		while ( $node !== null ) {
			if ( !$node instanceof Element ) {
				$node = $node->nextSibling;
				continue;
			}

			// !tplInfo check is to protect against templated content in
			// extensions which might in turn be nested in templated content.
			if ( !$tplInfo && WTUtils::isFirstEncapsulationWrapperNode( $node ) ) {
				$aboutSibs = WTUtils::getAboutSiblings( $node, $node->getAttribute( 'about' ) ?? '' );
				$tplInfo = (object)[
					'first' => $node,
					'last' => end( $aboutSibs ),
					'dsr' => DOMDataUtils::getDataParsoid( $node )->dsr ?? null,
					// FIXME: This is not being used. Instead the code is recomputing
					// this info in findEnclosingTemplateName.
					'isTemplated' => DOMUtils::hasTypeOf( $node, 'mw:Transclusion' ),
					'clear' => false,
				];
			}

			$nextNode = false;
			// Let native extensions lint their content
			$nativeExt = WTUtils::getNativeExt( $env, $node );
			if ( $nativeExt ) {
				$nextNode = $nativeExt->lintHandler(
					$this->extApi,
					$node,
					function ( $extRootNode ) use ( $env, $tplInfo ) {
						return $this->findLints( $extRootNode, $env,
							empty( $tplInfo->isTemplated ) ? null : $tplInfo );
					}
				);
			}
			if ( $nextNode === false ) {
				// Default node handler
				// Lint this node
				$nextNode = $this->logWikitextFixups( $node, $env, $tplInfo );
				if ( $tplInfo && $tplInfo->clear ) {
					$tplInfo = null;
				}

				// Lint subtree
				if ( !$nextNode ) {
					$this->findLints( $node, $env, $tplInfo );
				}
			}

			if ( $tplInfo && $tplInfo->last === $node ) {
				$tplInfo = null;
			}

			$node = $nextNode ?: $node->nextSibling;
		}
	}

	/**
	 * This is only invoked on the top-level document
	 * @inheritDoc
	 */
	public function run(
		Env $env, Node $root, array $options = [], bool $atTopLevel = false
	): void {
		// Skip linting if we cannot lint it
		if ( !$env->getPageConfig()->hasLintableContentModel() ) {
			return;
		}

		$this->extApi = new ParsoidExtensionAPI( $env );
		$this->findLints( $root, $env );
		$this->postProcessLints( $env->getLints(), $env );
	}

}
