<?php

/**
 * Digital Financial Reporting taxonomy implementation
 *
 * @author Bill Seddon
 * @version 0.9
 * @Copyright (C) 2019 Lyquidity Solutions Limited
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Load the XBRL implementation
 */
require_once('XBRL.php');

use XBRL\Formulas\Resources\Variables\FactVariable;
use XBRL\Formulas\Resources\Filters\ConceptName;
use lyquidity\xml\QName;
use XBRL\Formulas\Resources\Assertions\ValueAssertion;

define( 'NEGATIVE_AS_BRACKETS', 'brackets' );
define( 'NEGATIVE_AS_MINUS', 'minus' );

class XBRL_DFR extends XBRL
{
	/**
	 *
	 * @var string
	 */
	public static $originallyStatedLabel = "";

	/**
	 * An array of conceptual model arcroles and relationships
	 * @var array|null
	 */
	private static $conceptualModelRoles;
	private static $defaultConceptualModelRoles;

	/**
	 * Returns the current set of conceptual model roles.  If not defined, creates a default set from?:
	 * http://xbrlsite.azurewebsites.net/2016/conceptual-model/reporting-scheme/ipsas/model-structure/ModelStructure-rules-ipsas-def.xml
	 * @param string $cacheLocation (optional)
	 * @return array
	 */
	public static function getConceptualModelRoles( $cacheLocation = null )
	{
		if ( is_null( self::$conceptualModelRoles ) )
		{
			$context = XBRL_Global::getInstance();
			if ( ! $context->useCache && $cacheLocation )
			{
				$context->cacheLocation = $cacheLocation;
				$context->useCache = true;
				$context->initializeCache();
			}

			$taxonomy = XBRL::withTaxonomy("http://xbrlsite.azurewebsites.net/2016/conceptual-model/cm-roles.xsd", "conceptual-model-roles", true);
			$taxonomy->context = $context;
			$taxonomy->addLinkbaseRef( "http://xbrlsite.azurewebsites.net/2016/conceptual-model/reporting-scheme/ipsas/model-structure/ModelStructure-rules-ipsas-def.xml", "conceptual-model");
			$roleTypes = $taxonomy->getRoleTypes();
			// $cm = $taxonomy->getTaxonomyForXSD("cm.xsd");
			// $nonDimensionalRoleRef = $cm->getNonDimensionalRoleRefs( XBRL_Constants::$defaultLinkRole );
			// $cmArcRoles = $nonDimensionalRoleRef[ XBRL_Constants::$defaultLinkRole ];

			$originallyStated = array_filter( $roleTypes['link:label'], function( $role ) { return $role['id']; } );
			self::$originallyStatedLabel = reset( $originallyStated )['roleURI'];

			self::setConceptualModelRoles( $taxonomy );
			self::$defaultConceptualModelRoles = self::$conceptualModelRoles;

			unset( $taxonomy );
			XBRL::reset();

			// self::$conceptualModelRoles = $cmArcRoles;
		}
		return self::$conceptualModelRoles;

	}

	/**
	 * Sets some model roles if there are any in the taxonomy or sets the default model roles
	 * @param XBRL $taxonomy A taxonomy to use or null
	 */
	public static function setConceptualModelRoles( $taxonomy = null )
	{
		$cmTaxonomy = $taxonomy ? $taxonomy->getTaxonomyForXSD("cm.xsd") : null;
		if ( $cmTaxonomy )
		{
			$nonDimensionalRoleRef = $cmTaxonomy->getNonDimensionalRoleRefs( XBRL_Constants::$defaultLinkRole );
			if ( isset( $nonDimensionalRoleRef[ XBRL_Constants::$defaultLinkRole ] ) )
			{
				$cmArcRoles = $nonDimensionalRoleRef[ XBRL_Constants::$defaultLinkRole ];
				self::$conceptualModelRoles = $cmArcRoles;

				return;
			}
		}

		// Fallback
		self::$conceptualModelRoles = self::$defaultConceptualModelRoles;
	}

	/**
	 * Holds a list of features
	 * @var array
	 */
	private $features = array();

	/**
	 * How to style negative numbers
	 * @var string NEGATIVE_AS_BRACKETS | NEGATIVE_AS_MINUS
	 */
	private $negativeStyle = NEGATIVE_AS_BRACKETS;

	/**
	 * When true, any columns that contain no values or only closing balance values will be removed
	 * @var string
	 */
	private $stripEmptyColumns = false;

	/**
	 * A fixed list of dimensions to exclude when determining if there should be a grid layout
	 * @var array
	 */
	private $axesToExclude = array();

	/**
	 * A list of aliases for the DFR ReportDateAxis
	 * @var array
	 */
	private $reportDateAxisAliases = array();

	// Private variables for the function validateDFR
	/**
	 * A list of primary items within each ELR in the presentation linkbase being evaluated
	 * @var [][] $presentationPIs
	 */
	private $presentationPIs = array();
	/**
	 * A list of primary items within each ELR in the calculation linkbase being evaluated
	 * @var [][] $calculationPIs
	 */
	private $calculationPIs = array();
	/**
	 * A list of primary items within each ELR in the definition linkbase being evaluated
	 * @var [][] $definitionPIs
	 */
	private $definitionPIs = array();

	/**
	 * A list of the calculation networks or roles defined in the taxonomy.
	 * By default the full structure is not realized so this variable holds
	 * the realized network so they do not have to be realized repeatedly.
	 * @var array
	 */
	private $calculationNetworks = array();
	/**
	 * A list of the definition networks or roles defined in the taxonomy.
	 * By default the full structure is not realized so this variable holds
	 * the realized network so they do not have to be realized repeatedly.
	 * @var array $definitionNetworks
	 */
	private $definitionNetworks = array();
	/**
	 * A list of the presentation networks or roles defined in the taxonomy.
	 * By default the full structure is not realized so this variable holds
	 * the realized network so they do not have to be realized repeatedly.
	 * @var array $presentationNetworks
	 */
	private $presentationNetworks = array();

	/**
	 * Created by the constructor to hold the list of valid presentation relationships
	 * @var array
	 */
	private $allowed = array();

	/**
	 * Default constructor
	 */
	function __construct()
	{
		$this->features = array( "conceptual-model" => array(
			'PeriodAxis' => 'PeriodAxis',
			'ReportDateAxis' => XBRL_Constants::$dfrReportDateAxis,
			'ReportingEntityAxis' => XBRL_Constants::$dfrReportingEntityAxis,
			'LegalEntityAxis' => XBRL_Constants::$dfrLegalEntityAxis,
			'ConceptAxis' => XBRL_Constants::$dfrConceptAxis,
			'BusinessSegmentAxis' => XBRL_Constants::$dfrBusinessSegmentAxis,
			'GeographicAreaAxis' => XBRL_Constants::$dfrGeographicAreaAxis,
			'OperatingActivitiesAxis' => XBRL_Constants::$dfrOperatingActivitiesAxis,
			'InstrumentAxis' => XBRL_Constants::$dfrInstrumentAxis,
			'RangeAxis' => XBRL_Constants::$dfrRangeAxis,
			'ReportingScenarioAxis' => XBRL_Constants::$dfrReportingScenarioAxis,
			'CalendarPeriodAxis' => XBRL_Constants::$dfrCalendarPeriodAxis,
			'ReportDateAxis' => XBRL_Constants::$dfrReportDateAxis,
			'FiscalPeriodAxis' => XBRL_Constants::$dfrFiscalPeriodAxis,
			'origionallyStatedLabel' => 'origionallyStated',
			'restatedLabel' => XBRL_Constants::$labelRoleRestatedLabel,
			'periodStartLabel' => XBRL_Constants::$labelRolePeriodStartLabel,
			'periodEndLabel' => XBRL_Constants::$labelRolePeriodEndLabel
		) );

		$this->axesToExclude = array(
			'PeriodAxis', // Exists or implied
			XBRL_Constants::$dfrLegalEntityAxis, // Exists or implied
			XBRL_Constants::$dfrReportDateAxis, // Adjustment
			'CreationDateAxis', // ifrs and us-gaap ReportDateAxis
			// XBRL_Constants::$dfrReportingScenarioAxis // Variance
		);

		$this->reportDateAxisAliases = array(
			'CreationDateAxis', // ifrs and us-gaap
			XBRL_Constants::$dfrReportDateAxis
		);


		$cmArcRoles = XBRL_DFR::getConceptualModelRoles();

		$this->allowed = $cmArcRoles[ XBRL_Constants::$arcRoleConceptualModelAllowed ]['arcs'];
		if ( ! isset( $allowed['cm.xsd#cm_Concept'] ) )
		{
			$this->allowed['cm.xsd#cm_Concept'] = array();
		}

	}

	/**
	 * This function allows a descendent to do something with the information before it is deleted if helpful
	 * This function can be overridden by a descendent class
	 *
	 * @param array $dimensionalNode A node which has element 'nodeclass' === 'dimensional'
	 * @param array $parentNode
	 * @return bool True if the dimensional information should be deleted
	 */
	protected function beforeDimensionalPruned( $dimensionalNode, &$parentNode )
	{
		return false;
	}

	/**
	 * Gets an array containing a list of extra features supported usually by descendent implementation
	 * @param string $feature (optional) If supplied just the array for the feature is returned or all
	 * 									 features.  If supplied and not found an empty array is returned
	 * @return array By default there are no additional features so the array is empty
	 */
	public function supportedFeatures( $feature = null )
	{
		return $feature
			? ( isset( $this->features[ $feature ] ) ? $this->features[ $feature ] : array() )
			: $this->featrues;
	}

	/**
	 * Returns an array of preferred label pairs.  In the base XBRL instance is only the PeriodStart/PeriodEnd pair.
	 * @return string[][]
	 */
	public function getBeginEndPreferredLabelPairs()
	{
		$result = parent::getBeginEndPreferredLabelPairs();
		$result[] = array(
			self::$originallyStatedLabel,
			XBRL_Constants::$labelRoleRestatedLabel,
		);
		$result[] = array(
			// self::$originallyStatedLabel,
			XBRL_Constants::$labelRoleVerboseLabel,
		);

		return $result;
	}

	/**
	 * Renders an evidence package for a set of networks
	 * @param array $networks
	 * @param XBRL_Instance $instance
	 * @param XBRL_Formulas $formulas
	 * @param Observer $observer
	 * @param bool $echo
	 * @return array
	 */
	public function renderPresentationNetworks( $networks, $instance, $formulas, $observer, $evaluationResults, $echo = true )
	{
		$result = array();

		foreach ( $networks as $elr => $network )
		{
			// error_log( $elr );
			$result[ $elr ] = array(
				'entities' => $this->renderPresentationNetwork( $network, $elr, $instance, $formulas, $observer, $evaluationResults, $echo ),
				'text' => $networks[ $elr ]['text']
			);
		}

		return $result;
	}

	/**
	 * Renders an evidence package for a network
	 * @param array $network
	 * @param string $elr
	 * @param XBRL_Instance $instance
	 * @param XBRL_Formulas $formulas
	 * @param Observer $observer
	 * @param bool $echo
	 * @return array
	 */
	public function renderPresentationNetwork( $network, $elr, $instance, $formulas, $observer, $evaluationResults, $echo = true )
	{
		$entities = $instance->getContexts()->AllEntities();

		// Add a depth to each node
		$addDepth = function( &$nodes, $depth = 0 ) use( &$addDepth )
		{
			foreach ( $nodes as $label => &$node )
			{
				$node['depth'] = $depth;
				if ( ! isset( $node['children'] ) ) continue;
				$addDepth( $node['children'], $depth + 1 );
			}
			unset( $node );
		};

		$addDepth( $network['hierarchy'] );

		$result = array();

		foreach ( $entities as $entity )
		{
			$entityQName = qname( $entity );

			$result[ $entity ] = $this->renderNetworkReport( $network, $elr, $instance, $entityQName, $formulas, $observer, $evaluationResults, $echo );
		}

		return $result;
	}

	/**
	 * Validate the the taxonomy against the model structure rules
	 * @param XBRL_Formulas $formula An evaluated formulas instance
	 * @return array|null
	 */
	public function validateDFR( $formulas )
	{
		global $reportModelStructureRuleViolations;

		$log = XBRL_Log::getInstance();

		// Makes sure they are reset in case the same taxonomy is validated twice.
		$this->calculationNetworks = array();
		$this->presentationNetworks = array();
		$this->definitionNetworks = $this->getAllDefinitionRoles();

		$this->generateAllDRSs();

		foreach ( $this->definitionNetworks as $elr => &$roleRef )
		{
			$roleRef = $this->getDefinitionRoleRef( $elr );

			if ( property_exists( $this, 'definitionRoles' ) && ! in_array( $elr, $this->definitionRoles ) )
			{
				unset( $this->definitionNetworks[ $elr ] );
				continue;
			}

			// Capture primary items
			$this->definitionPIs[ $elr ] = array_filter( array_keys( $roleRef['primaryitems'] ), function( $label )
			{
				$taxonomy = $this->getTaxonomyForXSD( $label );
				$element = $taxonomy->getElementById( $label );
				return ! $element['abstract' ];
			} );

			sort( $this->definitionPIs[ $elr ] );

			// Check members
			foreach ( $roleRef['members'] as $memberLabel => $member )
			{
				$memberTaxonomy = $this->getTaxonomyForXSD( $memberLabel );
				$memberElement = $memberTaxonomy->getElementById( $memberLabel );

				if ( ! $memberElement['abstract' ] )
				{
					$log->business_rules_validation('Model Structure Rules', 'All dimension member elements MUST be abstract',
						array(
							'member' => $memberLabel,
							'role' => $elr,
							'error' => 'error:MemberRequiredToBeAbstract'
						)
					);
				}

				// BMS 2019-03-23 TODO typed members MUST NOT use complex types

				unset( $memberTaxonomy );
				unset( $memberElement );
			}

			// Check hypercube
			if ( $reportModelStructureRuleViolations )
			foreach ( $roleRef['hypercubes'] as $hypercubeLabel => $hypercube )
			{
				if ( ! isset( $hypercube['parents'] ) ) continue;

				foreach ( $hypercube['parents'] as $primaryItemLabel => $primaryItem )
				{
					if ( ! isset( $primaryItem['closed'] ) || ! $primaryItem['closed'] )
					{
						if ( ! isset( $this->definitionNetworks[ $elr ]['primaryitems'][ $primaryItemLabel ]['parents']  ) ) // Only report the error on the line items node
						{
							$log->business_rules_validation('Model Structure Rules', 'All line items to hypercubes MUST be closed',
								array(
									'hypercube' => $hypercubeLabel,
									'primary item' => $primaryItemLabel,
									'role' => $elr,
									'error' => 'error:HypercubesRequiredToBeClosed'
								)
							);
						}
					}

					if ( $primaryItem['arcrole'] == XBRL_Constants::$arcRoleNotAll )
					{
						$log->business_rules_validation('Model Structure Rules', 'All line items to hypercubes MUST be \'all\'',
							array(
								'hypercube' => $hypercubeLabel,
								'primary item' => $primaryItemLabel,
								'role' => $elr,
								'error' => 'error:HypercubeMustUseAllArcrole'
							)
						);
					}

					if ( $primaryItem['contextElement'] != XBRL_Constants::$xbrliSegment )
					{
						$log->business_rules_validation('Model Structure Rules', 'Dimensions in contexts MUST use the segment container',
							array(
								'hypercube' => $hypercubeLabel,
								'primary item' => $primaryItemLabel,
								'role' => $elr,
								'error' => 'error:DimensionsMustUseSegmentContainer'
							)
						);
					}
				}
			}
		}

		unset( $roleRef );

		$this->calculationNetworks = $this->getCalculationRoleRefs();
		$this->calculationNetworks = array_filter( $this->calculationNetworks, function( $roleRef ) { return isset( $roleRef['calculations'] ); } );
		foreach ( $this->calculationNetworks as $elr => $role )
		{
			if ( property_exists( $this, 'calculationRoles' ) && ! in_array( $elr, $this->calculationRoles ) )
			{
				unset( $this->calculationNetworks[ $elr ] );
				continue;
			}

			if ( ! isset( $role['calculations'] ) ) continue;

			foreach ( $role['calculations'] as $totalLabel => $components )
			{
				$calculationELRPIs = array_keys( $components );
				$calculationELRPIs[] = $totalLabel;

				$this->calculationPIs[ $elr ] = isset( $this->calculationPIs[ $elr ] )
					? array_merge( $this->calculationPIs[ $elr ], $calculationELRPIs )
					: $calculationELRPIs;
			}

			unset( $calculationELRPIs );
		}

		$this->presentationNetworks = &$this->getPresentationRoleRefs();

		if ( property_exists( $this, 'presentationRoles' ) )
		foreach ( $this->presentationNetworks as $elr => $role )
		{
			if ( in_array( $elr, $this->presentationRoles ) ) continue;
			unset( $this->presentationNetworks[ $elr ] );
		}

		// Check the definition and presentation roles are consistent then make sure the calculation roles are a sub-set
		if ( $this->definitionNetworks && array_diff_key( $this->presentationNetworks, $this->definitionNetworks ) || array_diff_key( $this->definitionNetworks, $this->presentationNetworks ) )
		{
			$log->business_rules_validation('Model Structure Rules', 'Networks in definition and presentation linkbases MUST be the same',
				array(
					'presentation' => implode( ', ', array_keys( array_diff_key( $this->presentationNetworks, $this->definitionNetworks ) ) ),
					'definition' => implode( ', ', array_keys( array_diff_key( $this->definitionNetworks, $this->presentationNetworks ) ) ),
					'error' => 'error:NetworksMustBeTheSame'
				)
			);
		}
		else
		{
			if ( array_diff_key( $this->calculationNetworks, $this->presentationNetworks ) )
			{
				$log->business_rules_validation('Model Structure Rules', 'Networks in calculation linkbases MUST be a sub-set of those in definition and presentation linkbases',
					array(
						'calculation' => implode( ', ', array_keys( array_diff_key( $this->calculationNetworks, $this->presentationNetworks ) ) ),
						'error' => 'error:NetworksMustBeTheSame'
					)
				);
			}
		}

		$presentationRollupPIs = array();

		foreach ( $this->presentationNetworks as $elr => &$role )
		{
			$this->presentationPIs[$elr] = array();

			foreach ( $role['locators'] as $id => $label )
			{
				$taxonomy = $this->getTaxonomyForXSD( $label );
				$element = $taxonomy->getElementById( $label );

				if ( $element['abstract'] || $element['type'] == 'nonnum:domainItemType' ) continue;

				// One or more of the labels may include the preferred label role so convert all PIs back to their id
				$this->presentationPIs[$elr][] = $taxonomy->getTaxonomyXSD() . "#{$element['id']}";

				// BMS 2019-03-23 TODO Check the concept is not a tuple
			}

			// If there were preferred label roles in any of the PIs then there will be duplicates.  This also sorts the list.
			$this->presentationPIs[ $elr ] = array_unique( $this->presentationPIs[ $elr ] );

			// This set of closures will become methods in a class
			$formulasForELR = array();
			if ( $formulas )
			{
				$variableSets = $formulas->getVariableSets();
				foreach ( $variableSets as $variableSetQName => $variableSetForQName )
				{
					foreach ( $variableSetForQName as /** @var ValueAssertion $variableSet */ $variableSet )
					{
						if ( $variableSet->extendedLinkRoleUri != $elr ) continue;
						if ( ! $variableSet instanceof ValueAssertion ) continue;
						$formulasForELR[] = $variableSet;
					}
				}
			}

			$calculationELRPIs = isset( $this->calculationPIs[ $elr ] ) ? $this->calculationPIs[ $elr ] : array();

			$axes = array();
			$lineItems = array();
			$tables = array();
			$concepts = array();

			// Access the list of primary items
			// $primaryItems = $this->getDefinitionPrimaryItems();
			$primaryItems = $this->getDefinitionRolePrimaryItems( $elr );
			$currentPrimaryItem = array();

			$this->processNodes( $role['hierarchy'], null, false, $this->allowed['cm.xsd#cm_Network'], false, $calculationELRPIs, $elr, $presentationRollupPIs, $tables, $lineItems, $axes, $concepts, $formulasForELR, $primaryItems, $currentPrimaryItem, null, null );

			if ( isset( $this->definitionNetworks[ $elr ] ) )
			{
				if ( $reportModelStructureRuleViolations && count( $tables ) != 1 )
				{
					XBRL_Log::getInstance()->business_rules_validation('Model Structure Rules', 'There MUST be one and only one table per network',
						array(
							'tables' => $tables ? implode( ', ', $tables ) : 'There is no table',
							'role' => $elr,
							'error' => 'error:MustBeOnlyOneTablePerNetwork'
						)
					);
				}

				if ( $reportModelStructureRuleViolations && count( $lineItems ) != 1 )
				{
					XBRL_Log::getInstance()->business_rules_validation('Model Structure Rules', 'There MUST be one and only one line items node per table',
						array(
							'lineitems' => $lineItems ? implode( ', ', $lineItems ) : 'There is no line item node',
							'role' => $elr,
							'error' => 'error:OneAndOnlyOneLineItems'
						)
					);
				}
			}
			else if ( $tables )
			{
				// If there are tables defined in the presentation but no tables in the definition then drop the presentation
				unset( $this->presentationNetworks['$elr'] );
			}

			$role['axes'] = $axes;
			$role['tables'] = $tables;
			$role['lineitems'] = $lineItems;
			$role['concepts'] = $concepts;
		}

		unset( $role );

		// The set of line items used in calculation, definition and presentation linkbases should be the same
		// First check there are consistent networks
		if ( $reportModelStructureRuleViolations )
		{
			$commonRoles = array_intersect_key( $this->definitionPIs, $this->presentationPIs );

			foreach ( $commonRoles as $elr => $role )
			{
				if ( isset( $presentationRollupPIs[ $elr ] ) )
				{
					$diff = array_unique( array_merge( array_diff( $presentationRollupPIs[ $elr ], $this->calculationPIs[ $elr ] ), array_diff( $this->calculationPIs[ $elr ], $presentationRollupPIs[ $elr ] ) ) );
					if ( $diff )
					{
						$log->business_rules_validation('Model Structure Rules', 'Calculation primary items MUST be the same as presentation items that are used in rollup blocks',
							array(
								'primary item' => implode( ',', $diff ),
								'role' => $elr,
								'error' => 'error:CalculationRelationsMissingConcept'
							)
						);
					}
				}

				$diff = array_unique( array_diff( $this->definitionPIs[ $elr ], $this->presentationPIs[ $elr ] ) );
				if ( $diff )
				{
					$log->business_rules_validation('Model Structure Rules', 'Presentation primary items MUST be the same as definition primary items',
						array(
							'primary item' => implode( ',', $diff ),
							'role' => $elr,
							'error' => 'error:PresentationRelationsMissingConcept'
						)
					);
				}

				$diff = array_unique( array_diff( $this->presentationPIs[ $elr ], $this->definitionPIs[ $elr ] ) );
				if ( $diff )
				{
					$log->business_rules_validation('Model Structure Rules', 'Definition primary items MUST be the same as presentation primary items',
						array(
							'primary item' => implode( ',', $diff ),
							'role' => $elr,
							'error' => 'error:DefinitionRelationsMissingConcept'
						)
					);
				}
			}
		}

		return $this->presentationNetworks;
	}

	/**
	 * Look for a concept in each formula's filter
	 * @param array $formulasForELR (ref) Array of formulas defined for the ELR
	 * @param XBRL_DFR $taxonomy
	 * @param array $element
	 * @return boolean
	 */
	private function findConceptInFormula( &$formulasForELR, $taxonomy, $element )
	{
		if ( ! $formulasForELR ) return false;

		$conceptClark = "{" . $taxonomy->getNamespace() . "}" . $element['name'];

		foreach ( $formulasForELR as $variableSet )
		{
			foreach ( $variableSet->variablesByQName as $qname => $variable )
			{
				if ( ! $variable instanceof FactVariable ) continue;
				foreach ( $variable->filters as $x => $filter )
				{
					if ( ! $filter instanceof ConceptName ) continue;
					foreach ( $filter->qnames as $clark )
					{
						if ( $clark == $conceptClark ) return true;;
					}
				}
			}
		}

		return false;
	}

	/**
	 * If there are class-equivalent arcs check all formula filters to see if they need to be updated
	 */
	public function fixupFormulas()
	{
		// Find the class-equivalentClass arc role(s)
		$taxonomies = $this->context->getTaxonomiesWithArcRoleTypeId('class-equivalentClass');
		$arcRoles = array_map( function( /** @var XBRL $taxonomy */ $taxonomy )
		{
			$arcRole = $taxonomy->getArcRoleTypeForId( 'class-equivalentClass' );
			if ( ! $arcRole ) return '';
			return str_replace( 'link:definitionArc/', '', $arcRole );
		}, $taxonomies  );

		if ( ! $arcRoles ) return;

		$nonDimensionalRoleRef = $this->getNonDimensionalRoleRefs();
		$classEquivalents = null;
		foreach ( $arcRoles as $arcRole )
		{
			if ( ! isset( $nonDimensionalRoleRef[ XBRL_Constants::$defaultLinkRole ][ $arcRole ] ) ) continue;
			$classEquivalents = $nonDimensionalRoleRef[ XBRL_Constants::$defaultLinkRole ][ $arcRole ];
			break;
		}

		if ( ! $classEquivalents ) return;

		foreach ( $this->getImportedSchemas() as $label => $taxonomy )
		{
			if ( ! $taxonomy->getHasFormulas() ) continue;

			$resources = $taxonomy->getGenericResource('filter', 'conceptName' );
			if ( ! $resources ) continue;

			$baseTaxonomy = null; // $this->getBaseTaxonomy() ? $this->getTaxonomyForXSD( $this->getBaseTaxonomy() ) : null;

			foreach ( $classEquivalents['arcs'] as $fac => $gaaps )
			{
				$facTaxonomy = $this->getTaxonomyForXSD( $fac );
				if ( ! $facTaxonomy ) continue;

				$facElement = $facTaxonomy->getElementById( $fac );
				if ( ! $facElement ) continue;

				$facClark = "{{$facTaxonomy->getNamespace()}}{$facElement['name']}";

				foreach ( $resources as $resource )
				{
					$changed = false;

					foreach ( $resource['filter']['qnames'] as $qnIndex => $qname )
					{
						if ( $qname != $facClark ) continue;

						foreach ( $gaaps as $gaapLabel => $gaap )
						{
							$gaapTaxonomy = $this->getTaxonomyForXSD( $gaapLabel );
							if ( ! $gaapTaxonomy ) continue;

							$gaapElement = $gaapTaxonomy->getElementById( $gaapLabel );
							if ( ! $gaapElement ) continue;

							// $gaapClark = "{{$gaapTaxonomy->getNamespace()}}{$gaapElement['name']}";
							$gaapClark = $baseTaxonomy
								? "{{$baseTaxonomy->getNamespace()}}{$gaapElement['name']}"
								: "{{$gaapTaxonomy->getNamespace()}}{$gaapElement['name']}";

							// echo "{$resource['linkbase']} - {$resource['resourceName']}: from $facClark to $gaapClark\n";

							//$taxonomy->genericRoles['roles']
							//		[ $resource['roleUri'] ]
							//		['resources']
							//		[ $resource['linkbase'] ]
							//		[ $resource['resourceName'] ]
							//		[ $resource['index'] ]['qnames'][ $qnIndex ] = $gaapClark;
							if ( $resource['filter']['qnames'][ $qnIndex ] == $facClark )
							{
								$resource['filter']['qnames'][ $qnIndex ] = $gaapClark;
							}
							else
							{
								$resource['filter']['qnames'][] = $gaapClark;
							}
							$changed = true;
						}
					}

					if ( $changed )
					{
						$taxonomy->updateGenericResource( $resource['roleUri'], $resource['linkbase'], $resource['resourceName'], $resource['index'], $resource['filter'] );
					}
				}
			}
		}
	}

	/**
	 * Return the label of an axis if it exists in $axes or false
	 * @param string $axisName
	 * @param array $axes
	 * @return string|boolean
	 */
	private function hasAxis( $axisName, $axes )
	{
		$dfrConceptualModel = $this->supportedFeatures('conceptual-model');

		$axisName = $dfrConceptualModel[ $axisName ];

		$axis = array();
		foreach ( $axisName == XBRL_Constants::$dfrReportDateAxis ? $this->reportDateAxisAliases : array( $axisName ) as $axisName )
		{
			$axis = array_filter( $axes, function( $axis ) use( $axisName )
			{
				return isset( $axis['dimension'] ) && $axis['dimension']->localName == $axisName;
			} );

			if ( $axis ) break;
		}

		return $axis ? key( $axis ) : false;
	}

	/**
	 *Test whether the $elr contains a $label
	 * @param string $label The label to find
	 * @param string $elr The extended link role to look in
	 * @param string $parentLabel
	 * @param string $source What hypercube aspect to use (primaryitems, members, dimensions)
	 * @param string $recurse If true the hierarchy will be tested recursively
	 * @return unknown|boolean|unknown|boolean
	 */
	private function hasHypercubeItem( $label, $elr, $parentLabel, $source = 'primaryitems', $recurse = true )
	{
		// if ( ! isset( $this->definitionNetworks[ $elr ] ) ) $this->definitionNetworks[ $elr ] = $this->getDefinitionRoleRef( $elr );
		if ( isset( $this->definitionNetworks[ $elr ][ $source ][ $label ] ) ) return $this->definitionNetworks[ $elr ][ $source ][ $label ];

		if ( $recurse )
		{
			// If not check for the label in a different ELR
			foreach ( $this->definitionNetworks as $elr2 => &$role )
			{
				// Ignore the same ELR
				if ( $elr == $elr2 ) continue;

				//
				$node = $this->hasHypercubeItem( $label, $elr2, $parentLabel, $source, false );
				if ( $node )
				{
					global $reportModelStructureRuleViolations;
					if ( $reportModelStructureRuleViolations )
					XBRL_Log::getInstance()->business_rules_validation('Model Structure Rules', ' Network relations for presentation, calculation, and definition relations MUST be defined in the same network.',
						array(
							'parent' => $parentLabel ? $parentLabel : 'Network',
							'concept' => $label,
							'expected role' => $elr,
							'actual role' => $elr,
							'error' => 'error:NetworkIdentifiersInconsistent'
						)
					);
					return $node;
				}
			}
		}

		return false;
	}

	/**
	 * Process the nodes for an ELR.  Returns the pattern type name for the block
	 * @param array		$noes A standard node hierarchy
	 * @param string	$parentLabel The label of the node that owns $nodes
	 * @param boolean	$parentIsAbstract True is the parent node is an abstract node
	 * @param array		$validNodeTypes A list of node types allowed for these nodes
	 * @param boolean	$underLineItems True if the set of nodes a descendent of a line items node
	 * @param array		$calculationELRPIs (ref) An array containing labels of calculation primary items
	 * @param string	$elr The current extended link role being processed
	 * @param array		$presentationRollupPIs (ref) A variable used to capture the priamry items used in rollup blocks
	 * @param array		$tables (ref)
	 * @param array		$lineItems (ref)
	 * @param array		$axes (ref)
	 * @param array		$concepts (ref)
	 * @param array		$formulasForELR (ref)
	 * @return string
	 */
	private function processNodes( &$nodes, $parentLabel, $parentIsAbstract, $validNodeTypes, $underLineItems, &$calculationELRPIs, $elr, &$presentationRollupPIs, &$tables, &$lineItems, &$axes, &$concepts, &$formulasForELR, &$primaryItems, &$currentPrimaryItem, $currentHypercubeLabel, $currentDimensionLabel )
	{
		$possiblePatternTypes = array();
		$patternType = ''; // Default pattern

		// Make sure the nodes are sorted by order
		uasort( $nodes, function( $nodea, $nodeb ) { return ( isset( $nodea['order'] ) ? $nodea['order'] : 0 ) - ( isset( $nodeb['order'] ) ? $nodeb['order'] : 0 ); } );

		// Create a list of labels that are not abstract
		$getNonAbstract = function( $nodes )
		{
			return array_filter( array_keys( $nodes ), function( $label )
			{
				$taxonomy = $this->getTaxonomyForXSD( $label );
				$element = $taxonomy->getElementById( $label );
				return ! $element['abstract'];
			} );
		};

		$nonAbstract = $getNonAbstract( $nodes );

		$firstNonAbstractLabel = reset( $nonAbstract );
		$lastNonAbstractLabel = end( $nonAbstract );

		foreach ( $nodes as $label => &$node )
		{
			$first = $label == $firstNonAbstractLabel;
			$last = $label == $lastNonAbstractLabel;

			$taxonomy = $this->getTaxonomyForXSD( $label );
			$element = $taxonomy->getElementById( $label );

			// Recreate the label because if the arc has a preferred label the label will include the preferred label to make the index unique
			$label = $taxonomy->getTaxonomyXSD() . "#{$element['id']}";
			if ( $first ) $firstNonAbstractLabel = $label;
			if ( $last ) $lastNonAbstractLabel = $label;

			$ok = false;
			$type = '';

			foreach( $this->allowed as $child => $detail )
			{
				if ( $ok ) continue;

				switch( $child )
				{
					case 'cm.xsd#cm_Table':
						$ok |= $taxonomy->context->types->resolveToSubstitutionGroup( $element['substitutionGroup'], array( XBRL_Constants::$xbrldtHypercubeItem ) );
						if ( $ok )
						{
							$tables[ $parentLabel ] = $label;
							$currentHypercubeLabel = $label;
						}
						break;

					case 'cm.xsd#cm_Axis':
						$ok |= $taxonomy->context->types->resolveToSubstitutionGroup( $element['substitutionGroup'], array( XBRL_Constants::$xbrldtDimensionItem ) );
						if ( $ok )
						{
							$currentDimensionLabel = $label;
							$defaultMember = isset( $this->context->dimensionDefaults[ $label ]['label'] ) ? $this->context->dimensionDefaults[ $label ]['label'] : false;

							if ( ! $defaultMember && in_array( $element['name'], $this->reportDateAxisAliases ) )
							{
								XBRL_Log::getInstance()->business_rules_validation('Model Structure Rules', 'Report Date [Axis] Missing Dimension Default',
									array(
										'axis' => $label,
										'role' => $elr,
										'error' => 'error:ReportDateDimensionMissingDimensionDefault'
									)
								);

							}

							$typedDomainRef = isset( $element['typedDomainRef'] ) ? $element['typedDomainRef'] : false;

							$axes[ $parentLabel ][ $label ] = array(
								'dimension' => new QName( $taxonomy->getPrefix(), $taxonomy->getNamespace(), $element['name'] ),
								'dimension-label' => $label,
								'default-member' => $defaultMember,
								'typedDomainRef' => $typedDomainRef,
								'members' => array()
							);

							$node['typedDomainRef'] = $typedDomainRef;
							$node['default-member'] = $defaultMember;
						}
						break;

					case 'cm.xsd#cm_Member':

						// Q Which test needs the condition: $element['type'] == 'nonnum:domainItemType'
						// A Hoffman test suite 3000 01-MemberAbstractAttribute
						if ( $currentHypercubeLabel && $currentDimensionLabel && $element['type'] == 'nonnum:domainItemType' )
						{
							if ( $currentPrimaryItem )
							{
								$drs = $this->getPrimaryItemDRSForRole( array( $elr => $currentPrimaryItem ), $elr );
								$ok = isset( $drs[ $currentHypercubeLabel ][ $elr ]['dimensions'][ $currentDimensionLabel ]['members'][ $label ] );
							}
							else
							{
								$ok = isset( $this->definitionNetworks[ $elr ]['members'][ $label ] );
							}

							if ( $ok )
							{
								$node['is-domain'] = false;
								$node['is-default'] = false;

								if ( ! isset( $axes[ $currentHypercubeLabel ][ $currentDimensionLabel ]['domain-member'] ) ) $axes[ $currentHypercubeLabel ][ $currentDimensionLabel ]['domain-member'] = false;
								if ( ! isset( $axes[ $currentHypercubeLabel ][ $currentDimensionLabel ]['root-member'] ) ) $axes[ $currentHypercubeLabel ][ $currentDimensionLabel ]['root-member'] = false;
								if ( isset( $axes[ $currentHypercubeLabel ][ $currentDimensionLabel ]['default-member'] ) && $axes[ $currentHypercubeLabel ][ $currentDimensionLabel ]['default-member'] == $label)
								{
									$node['is-default'] = true;
								}

								if ( $currentPrimaryItem )
								{
									$member = $drs[ $currentHypercubeLabel ][ $elr ]['dimensions'][ $currentDimensionLabel ]['members'][ $label ];

									if ( isset( $member['parents'][ $currentDimensionLabel ]['arcrole'] ) )
									{
										$arcrole = $member['parents'][ $currentDimensionLabel ]['arcrole'];
										if ( $arcrole == XBRL_Constants::$arcRoleDimensionDomain )
										{
											$axes[ $currentHypercubeLabel ][ $currentDimensionLabel ]['domain-member'] = $label;
											$node['is-domain'] = true;
										}
										unset( $arcrole );
									}
								}
								else
								{
									if ( isset( $this->definitionNetworks[ $elr ]['members'][ $label ]['parents'][ $parentLabel ]['arcrole'] ) )
									{
										$arcrole = $this->definitionNetworks[ $elr ]['members'][ $label ]['parents'][ $parentLabel ]['arcrole'];
										if ( $arcrole == XBRL_Constants::$arcRoleDimensionDomain )
										{
											$axes[ $currentHypercubeLabel ][ $parentLabel ]['domain-member'] = $label;
											$node['is-domain'] = true;
										}
										unset( $arcrole );
									}
								}

								// Note that $currentDimensionLabel cannot be used because the member parent might be another member not a dimension
								if ( isset( $axes[ $currentHypercubeLabel ][ $parentLabel ] ) && isset( $axes[ $currentHypercubeLabel ][ $parentLabel ]['dimension'] ) ) $axes[ $currentHypercubeLabel ][ $parentLabel ]['root-member'] = $label;
								$axes[ $currentHypercubeLabel ][ $parentLabel ]['members'][ $label ] = new QName( $taxonomy->getPrefix(), $taxonomy->getNamespace(), $element['name'] );
							}
						}
						break;

					case 'cm.xsd#cm_LineItems':
						if ( $element['abstract'] )
						{
							// BMS 2019-05-14 This probably needs to change to use the $primaryItems collection
							$item = $this->hasHypercubeItem( $label, $elr, $parentLabel, 'primaryitems', true );
							if ( $item && ! isset( $item['parents'] ) ) // a line item is a root primary item node
							{
								$ok = true;
								$lineItems[ $parentLabel ] = $label;
								if ( isset( $primaryItems[ $label ] ) ) $currentPrimaryItem = $primaryItems[ $label ];
							}
							unset( $item);
						}
						break;

					case 'cm.xsd#cm_Concept':
						// if ( $patternType == 'rollup' )
						// {
						//	$ok = true;
						//	break;
						// }

						if ( ! $element['abstract'] && $element['type'] != 'nonnum:domainItemType' && $this->isPrimaryItem( $element ) )
						{
							$ok = true;
							$concepts[ $label ] = new QName( $taxonomy->getPrefix(), $taxonomy->getNamespace(), $element['name'] );
							if ( isset( $primaryItems[ $label ] ) ) $currentPrimaryItem = $primaryItems[ $label ];

							if ( ! $possiblePatternTypes && in_array( $label, $calculationELRPIs ) )
							{
								// $ok = true;
								$patternType = 'rollup';
								if ( isset( $this->calculationNetworks[ $elr ]['calculations'][ $label ] ) )
								{
									$node['total'] = true;
								}
								$possiblePatternTypes = array();
								break;
							}

							// Add a list of the possible concept arrangemebt patterns
							//
							// This information comes from http://xbrlsite.azurewebsites.net/2017/IntelligentDigitalFinancialReporting/Part02_Chapter05.7_UnderstandingConceptArrangementPatternsMemberArrangementPatterns.pdf
							// starting with section 1.3.2
							//
							// Rollup: If the concept is in the calculation linkbase then the only pattern us rollup
							//
							// Roll forward: can be detected because
							// (a) it always has an instant as the first and last concept in the presentation relations,
							// (b) the first instant has a periodStart label role,
							// (c) the second instant concept is the same as the first and has the periodEnd label, and
							// (d) XBRL Formulas exist that represent the roll forward mathematical relation.
							//
							// Roll forward info: looks like a roll forward, but is not really a roll forward.
							// While a roll forward reconciles the balance of a concept between two points in time;
							// the roll forward info is really just a hierarchy which shows a beginning and ending
							// balance. A roll forward info concept arrangement pattern is generally shown with a
							// roll forward.  Roll forward info can be detected because:
							// (a) the first concept has a periodStart label,
							// (b) the last concept in the presentation relations has a periodEnd label.
							//
							// Adjustment: always has a 'Report Date [Axis]' and
							// (a) the first concept is an instant and uses non-default preferred label
							// (b) the last concept is an instant and uses the restated label role
							// Alias Concepts for 'Report Creation Date [Axis]' are 'us-gaap:CreationDateAxis' and 'ifrs-full:CreationDateAxis, frm:ReportDateAxis'
							//
							// Variance: can be a specialization of other concept arrangement patterns such as a
							// 			 [Hierarchy] as shown above, a [Roll Up] if the [Line Items] rolled up, or
							//			 even a [RollForward]. Uses the 'Reporting Scenario [Axis]'
							//
							// Aliases concepts are: 'usgaap:StatementScenarioAxis' (Seems missing from IFRS).
							//
							// Complex computation: can be identified because
							// (a) there are numeric relations and those relations do not follow any of the other
							//	   mathematical patterns
							// (b) there is an XBRL formula that represents a mathematical relation other than one
							//     of the other mathematical computation patterns.
							//
							// Text block can always be identified by the data type used to represent the text block
							// which will be: nonnum:textBlockItemType
							//

							if ( $possiblePatternTypes )
							{
								// Filter the list of possible pattern types
								if ( $last )
								{
									// Look for an ending label
									if ( isset( $node['preferredLabel'] ) && $node['preferredLabel'] == XBRL_Constants::$labelRolePeriodEndLabel )
									{
										if ( $element['periodType'] == 'instant' )
										{
											if ( in_array( 'rollforward', $possiblePatternTypes ) && ( isset( $calculationELRPIs[ $label ] ) || $this->findConceptInFormula( $formulasForELR, $taxonomy, $element ) ) )
											{
												$patternType = "rollforward";
												$possiblePatternTypes = array();
												break;
											}
										}

										if ( in_array( 'rollforwardinfo', $possiblePatternTypes ) )
										{
											$patternType = "rollforwardinfo";
											// $patternType = "rollforward";
											$possiblePatternTypes = array();
											break;
										}
									}

									if ( isset( $node['preferredLabel'] ) ) // && $node['preferredLabel'] == XBRL_Constants::$labelRoleRestatedLabel )
									{
										if ( $element['periodType'] == 'instant' )
										{
											if ( in_array( 'adjustment', $possiblePatternTypes ) )
											{
												$patternType = "adjustment";
												$possiblePatternTypes = array();
												break;
											}
										}
									}

									if ( in_array( 'complex', $possiblePatternTypes ) || $this->findConceptInFormula( $formulasForELR, $taxonomy, $element ) )
									{
										$patternType = "complex";
										$possiblePatternTypes = array();
										break;
									}

									if ( $element['type'] == 'nonnum:textBlockItemType' && in_array( 'text', $possiblePatternTypes ) )
									{
										$patternType = 'text';
										$possiblePatternTypes = array();
										break;
									}
								}

								if ( ! in_array( 'complex', $possiblePatternTypes ) && $this->findConceptInFormula( $formulasForELR, $taxonomy, $element ) )
								{
									$possiblePatternTypes[] = 'complex';
								}

							}
							else
							{
								if ( $first )
								{
									// Roll forward
									// Roll forward info
									if ( isset( $node['preferredLabel'] ) && $node['preferredLabel'] == XBRL_Constants::$labelRolePeriodStartLabel )
									{
										$possiblePatternTypes[] = 'rollforwardinfo';
										if ( $element['periodType'] == 'instant' && ( isset( $calculationELRPIs[ $label ] ) || $this->findConceptInFormula( $formulasForELR, $taxonomy, $element ) ) )
										{
											$possiblePatternTypes[] = 'rollforward';
										}
									}

									// Adjustment
									if ( isset( $node['preferredLabel'] ) ) // && ( $node['preferredLabel'] == XBRL_DFR::$originallyStatedLabel || $node['preferredLabel'] == XBRL_Constants::$labelRoleVerboseLabel ) )
									{
										// MUST be an instant period type and have a report date axis
										if ( $element['periodType'] == 'instant' && isset( $axes[ $currentHypercubeLabel ] ) && $this->hasAxis( 'ReportDateAxis', $axes[ $currentHypercubeLabel ] ) )
										{
											$possiblePatternTypes[] = 'adjustment';
										}
									}

									// Text
									if ( $element['type'] == 'nonnum:textBlockItemType' )
									{
										// $patternType = 'text';
										$possiblePatternTypes[] = 'text';
									}
								}

								// Complex
								if ( $this->findConceptInFormula( $formulasForELR, $taxonomy, $element ) )
								{
									if ( $last )
									{
										$patternType = "complex";
									}
									else
									{
										$possiblePatternTypes[] = 'complex';
									}
								}
							}
						}
						break;

					case 'cm.xsd#cm_Abstract':
						// Abstract is low priority - do it later if necessary
						break;

					default:
						// Do nothing
						break;

				}

				if ( $ok )
				{
					$node['modelType'] = $child;
					break;
				}
			}

			if ( ! $ok /* && isset( $validNodeTypes['cm.xsd#cm_Abstract'] ) */ )
			{
				if ( $element['abstract'] && $taxonomy->context->types->resolveToSubstitutionGroup( $element['substitutionGroup'], array( XBRL_Constants::$xbrliItem ) ) )
				{
					$ok = true;
					$node['modelType'] = $child = 'cm.xsd#cm_Abstract';
				}
			}

			if ( ! isset( $node['modelType'] ) )
			{
				// Something has gone wrong
				XBRL_Log::getInstance()->warning( "Node without a model type: " . $label );
				continue;
			}

			if ( ! $ok || ! isset( $validNodeTypes[ $node['modelType'] ] ) )
			{
				global $reportModelStructureRuleViolations;
				if ( $reportModelStructureRuleViolations )
				XBRL_Log::getInstance()->business_rules_validation('Model Structure Rules', 'Invalid model structure',
					array(
						'parent' => $parentLabel ? $parentLabel : 'Network',
						'concept' => $label,
						'expected' => $validNodeTypes ? implode(', ', array_keys( $validNodeTypes ) ) : 'There are no allowed node types for the parent node',
						'role' => $elr,
						'error' => 'error:InvalidModelStructure'
					)
				);
			}

			// Set the pattern type here
			if ( ! isset( $node['children'] ) ) continue;
			if ( ! isset( $this->allowed[ $node['modelType'] ] ) )
			{
				global $reportModelStructureRuleViolations;
				if ( $reportModelStructureRuleViolations )
				XBRL_Log::getInstance()->business_rules_validation('Model Structure Rules', 'Invalid model structure.  The computed model type is not allowed at this point',
					array(
						'parent' => $parentLabel ? $parentLabel : 'Network',
						'concept' => $label,
						'expected' => implode(', ', array_keys( $validNodeTypes ) ),
						'model type' => $child,
						'role' => $elr,
						'error' => 'error:InvalidModelStructure'
					)
				);
				continue;
			}

			$isLineItems = $node['modelType'] == 'cm.xsd#cm_LineItems';
			$isAbstract = $node['modelType'] == 'cm.xsd#cm_Abstract';
			$underLineItems |= $isLineItems;
			$result = $this->processNodes( $node['children'], $label, $isAbstract, $this->allowed[ $child ], $underLineItems, $calculationELRPIs, $elr, $presentationRollupPIs, $tables, $lineItems, $axes, $concepts, $formulasForELR, $primaryItems, $currentPrimaryItem, $currentHypercubeLabel, $currentDimensionLabel );
			$node['patterntype'] = $result;

			if ( $underLineItems && ( $isAbstract || $isLineItems ) && ! $result )
			{
				$result = 'set'; // Add a default if one not provided
			}

			if ( $underLineItems && $result )
			{
				$node['variance'] = false;
				$node['grid'] = false;

				// May be a variance
				$hypercubeLabel = $tables ? reset( $tables ) : false;

				// See if there is a report scenario axis
				$varianceAxis = $hypercubeLabel && isset( $axes[ $hypercubeLabel ] )
					? $this->hasAxis( 'ReportingScenarioAxis', $axes[ $hypercubeLabel ] )
					: false;

				if ( $varianceAxis )
				{
					// Note: these tests could be combined into one composite
					// test but broken out its easier to see what's going on

					// BMS 2019-03-23 Need to check that there is one parent with two members otherwise its a grid

					// There must be more than one member
					$members = $axes[ $hypercubeLabel ][ $varianceAxis ]['members'];
					if ( count( $members ) > 1 )
					{
						$node['variance'] = $varianceAxis;
					}
					else if ( $members )
					{
						// Check to see if there are nested members.  Only one additional member is required
						if ( isset( $axes[ $hypercubeLabel ][ key( $members ) ] ) && count( $axes[ $hypercubeLabel ][ key( $members ) ]['members'] ) )
						{
							$node['variance'] = $varianceAxis;
						}
					}

				}
				// If not a variance then maybe a grid?
				if ( $hypercubeLabel && ! $node['variance'] )
				{
					$otherAxes = array_filter( $axes[ $hypercubeLabel ], function( $axis )
					{
						return isset( $axis['dimension'] ) && ( ! in_array( $axis['dimension']->localName, $this->axesToExclude ) );
					} );

					if ( $otherAxes )
					{
						$node['grid'] = $otherAxes;
					}
				}
			}

			if ( $result == "rollup" )
			{
				// Check that the calculation components are not mixed up
				// Check that the node children can be described by the members of just one calculation relationship
				// Begin by checking to see if the children have a total member
				$isDescendent = function( $label, $child ) use( &$isDescendent, $elr )
				{
					if ( ! isset( $this->calculationNetworks[ $elr ]['calculations'][ $label ] ) ) return false;

					$children = $this->calculationNetworks[ $elr ]['calculations'][ $label ];
					if ( isset( $children[ $child ] ) ) return true;

					foreach ( $children as $subChild )
					{
						if ( $isDescendent( $subChild['label'], $child ) ) return true;
					}

					return false;
				};

				$error = false;
				$totals = array_intersect_key( $this->calculationNetworks[ $elr ]['calculations'], $node['children'] );
				$error = count( $totals ) > 1;
				if ( $error )
				{
					// Check to see if one is the parents of all the others
					foreach ( $totals as $calcLabel => $calcChildren )
					{
						// Get a list of the other calculations totals so they can be tested
						$rest = array_filter( array_keys( $totals ), function( $label ) use( $calcLabel ) { return $label != $calcLabel; } );
						$found = true; // Assume success
						// Check each of the other totals to see if the primary is the parent of all the others
						foreach ( $rest as $other )
						{
							// If the other is a descendent of the primary check the other
							if ( $isDescendent( $calcLabel, $other ) ) continue;
							$found = false;
							break;
						}

						// When $found is true it means all the other are a child of the primary
						if ( $found )
						{
							$error = false;
							$totals = array( $calcLabel => $totals[ $calcLabel ] );
							break;
						}
					}
				}

				if ( ! $error )
				{
					$nonAbstractNodes = $getNonAbstract( $node['children'] );

					if ( $totals )
					{
						// Its an error if all the node members are not described by this relation
						$total = key( $totals );
						foreach ( $nonAbstractNodes as $nonAbstractNode )
						{
							if ( $nonAbstractNode == $total ) continue;
							if ( $isDescendent( $total, $nonAbstractNode ) ) continue;
							$error = true;
							break;
						}
					}
					else
					{
						// If there are no totals loop through each calculation to find a relationship that encompasses all children
						// Assume the worst
						$error = true;
						foreach ( $this->calculationNetworks[ $elr ]['calculations'] as $totalLabel => $components )
						{
							$diff = array_diff( $nonAbstractNodes, array_keys( $components ) );
							if ( ! $diff )
							{
								// Found a matching set
								$error = false;
								break;
							}
						}
					}
				}

				if ( $error )
				{
					XBRL_Log::getInstance()->business_rules_validation('Model Structure Rules', 'A rollup MUST contain components from only one calculation relationship set',
						array(
							'rollup' => $label,
							'role' => $elr,
							'error' => 'error:BlocksRunTogether'
						)
					);
				}

				// Filter any non-PI nodes.  This occurs in the pathalogical test case when a dimension member is a rollup.
				$pis = array_filter( array_keys( $node['children'] ), function( $label )
				{
					$taxonomy = $this->getTaxonomyForXSD( $label );
					$element = $taxonomy->getElementById( $label );
					return ! $element['abstract'] && $this->isPrimaryItem( $element );
				} );

				// Capture the elements in node['children']
				$presentationRollupPIs[ $elr ] = isset( $presentationRollupPIs[ $elr ] )
					? array_merge( $presentationRollupPIs[ $elr ], $pis )
					: $pis;
			} // $result == rollup

		} // $nodes

		unset( $node );

		if ( empty( $patternType ) && $underLineItems )
		{
			$patternType = "set";
		}
		return $patternType;
	}

	/**
	 * Renders the component table
	 * @param array $network
	 * @param string $elr
	 * @return string
	 */
	private function renderComponentTable( $network, $elr )
	{
		$table = $this->getTaxonomyDescriptionForIdWithDefaults( reset( $network['tables'] ) );

		$componentTable =
			"	<div class='component-table'>" .
			"		<div class='ct-header'>Component: Network plus Table</div>" .
			"		<div class='ct-body'>" .
			"			<div class='ct-body-header network'>Network</div>" .
			"			<div class='ct-body-content network'>" .
			"				<div>{$network['text']}</div>" .
			"				<div>$elr</div>" .
			"			</div>" .
			"			<div class='ct-body-header hypercube'>Table</div>" .
			"			<div class='ct-body-content hypercube'>$table</div>" .
			"		</div>" .
			"	</div>";

		return $componentTable;
	}

	/**
	 * Render a report with columns for any years and dimensions
	 * @param array $network			An array generated by the validsateDLR process
	 * @param string $elr				The extended link role URI
	 * @param XBRL_Instance $instance	The instance being reported
	 * @param QName $entityQName
	 * @param array $factsLayout		A table produced by the report table method
	 * @return string
	 */
	private function renderFactsTable( $network, $elr, $instance, $entityQName, &$reportsFactsLayout )
	{
		$axes = array_reduce( $network['axes'], function( $carry, $axes )
		{
			$carry = array_merge( $carry, $axes );
			return $carry;
		}, array() );

		$axes = array_filter( $axes, function( $axis ) { return isset( $axis['dimension'] ); } );

		$factsTable =
			"	<div class='facts-section hide-section'>";

		foreach ( $reportsFactsLayout as $reportLabel => $factsLayout )
		{
			$columnCount = 6 + count( $factsLayout['axes'] );

			if ( is_numeric( $reportLabel ) )
			{
				$reportTitle = 'main report facts';
			}
			else
			{
				$reportTaxonomy = $this->getTaxonomyForXSD( $reportLabel );
				$reportTitle = "sub-report '" . $reportTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $reportLabel ) . "'";
			}

			$repeatCount = $columnCount - 1;
			$factsTable .=
				"		<div class='fact-section-title'>Fact table for $reportTitle</div>" .
				"		<div style='display: grid; grid-template-columns: auto 1fr;'>" .
				"			<div class='fact-table' style='display: grid; grid-template-columns: minmax(auto,auto) repeat($repeatCount, auto);'>" .
				"				<div class='fact-table-header'>Context</div>" .
				"				<div class='fact-table-header'>Period [Axis]</div>";

			foreach ( $factsLayout['axes'] as $axisLabel )
			{
				$axis = $axes[ $axisLabel ];

				$dimTaxonomy = $instance->getInstanceTaxonomy()->getTaxonomyForXSD( $axisLabel );
				$text = $dimTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $axisLabel );
				$factsTable .=
					"			<div class='fact-table-header'>$text</div>";
			}

			$factsTable .=
				"				<div class='fact-table-header'>Concept</div>" .
				"				<div class='fact-table-header'>Value</div>" .
				"				<div class='fact-table-header'>Unit</div>" .
				"				<div class='fact-table-header last'>Rounding</div>";

			foreach ( $factsLayout['data'] as $conceptLabel => $row )
			{
				/** @var XBRL $conceptTaxonomy */
				$conceptTaxonomy = $row['taxonomy'];
				$conceptText = $conceptTaxonomy->getTaxonomyDescriptionForIdWithDefaults( '#' . $row['element']['id'] );

				foreach ( $row['columns'] as $columnIndex => $fact )
				{
					$context = $instance->getContext( $fact['contextRef'] );
					$period = $context['period']['is_instant']
						? $context['period']['endDate']
						: "{$context['period']['startDate']} - {$context['period']['endDate']}";

					$dimensions = $instance->getContextDimensions( $context );

					$type = (string) XBRL_Instance::getElementType( $fact );
					$valueClass = empty( $type ) ? '' : $conceptTaxonomy->valueAlignment( $type, $instance );

					$factsTable .=
						"				<div class='fact-table-line'><span class='contextRef'>{$fact['contextRef']}</span></div>" .
						"				<div class='fact-table-line'>$period</div>";

					foreach ( $factsLayout['axes'] as $axisLabel )
					{
						$axis = $axes[ $axisLabel ];

						$dimTaxonomy = $instance->getInstanceTaxonomy()->getTaxonomyForXSD( $axisLabel );
						$dimElement = $dimTaxonomy->getElementById( $axisLabel );

						$dimQName = $dimTaxonomy->getPrefix() . ":" . $dimElement['name'];

						if ( isset( $axis['typedDomainRef'] ) && $axis['typedDomainRef'] && isset( $dimensions[ $dimQName ] ) )
						{
							$label = $axis['typedDomainRef'];
							$memTaxonomy = $dimTaxonomy;
							if ( $label[0] != '#' )
							{
								$memTaxonomy = $dimTaxonomy->getTaxonomyForXSD( $label );
							}

							$memElement = $memTaxonomy->getElementById( $label );
							$memQName = "{$memTaxonomy->getPrefix()}:{$memElement['name']}";
							$memberText = preg_replace( array( "!<$memQName>!", "!</$memQName>!" ), "", reset( $dimensions[ $dimQName ][ $memQName ] ) );
						}
						else
						{
							if ( isset( $dimensions[ $dimQName ] ) )
							{
								{
									$memQName = qname( $dimensions[ $dimQName ], $instance->getInstanceNamespaces() );
									$memTaxonomy = $dimTaxonomy->getTaxonomyForQName( $dimensions[ $dimQName ] );
									$memElement = $memTaxonomy->getElementByName( $memQName->localName );
									$memberLabel = $memTaxonomy->getTaxonomyXSD() . "#" . $memElement['id'];
								}
							}
							else
							{
								$memberLabel = $axis['default-member'];
								$memTaxonomy = $dimTaxonomy->getTaxonomyForXSD( $memberLabel );
							}

							$memberText = $memTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $memberLabel );
						}
						$factsTable .=
							"				<div class='fact-table-line'>$memberText</div>";
					}

					$factsTable .=
						"				<div class='fact-table-line'>$conceptText</div>" .
						"				<div class='fact-table-line $valueClass'>{$fact['value']}</div>" .
						"				<div class='fact-table-line'>{$fact['unitRef']}</div>" ;

					$factsTable .= isset( $fact['decimals'] )
						? "				<div class='fact-table-line last'>{$fact['decimals']}</div>"
						: "				<div class='fact-table-line last'></div>";

				}
			}

			$factsTable .=
				"			</div>" .
				"			<div></div>" .
				"		</div>";

		}

		$factsTable .=
			"	</div>";

		return $factsTable;

	}

	/**
	 * Renders the slicers table
	 * @param array $network
	 * @param XBRL_Instance $instance
	 * @param QName $entityQName
	 * @param array $axes (def: null) A list of axes
	 * @param string $class (def: 'slicers-table') The class name to apply to the table
	 * @param ContextsFilter $contextsFilter (def: null) A collection of contexts used to determine the axis members to select
	 * @return string
	 */
	private function renderSlicers( $network, $instance, $entityQName, $axes = null, $class = null, $contextsFilter = null )
	{
		if ( is_null( $class ) ) $class = 'slicers-table';

		$slicers =
			"	<div class='$class'>" .
			// "		<div>Slicers</div>" .
			"		<div class='slicers'>" .
			"			<div class='slicer-header'>Reporting Entity [Axis]</div>" .
			"			<div class='slicer-content'>{$entityQName->localName} ({$entityQName->namespaceURI})</div>";

		$hasMultipleMembers = function( &$axes, &$axis)
		{
			if ( count( $axis['members'] ) > 1 ) return true;
			$memberLabel = key($axis['members'] );
			return isset( $axes[ $memberLabel ] );
		};

		if ( is_null( $axes ) )
		{
			$hypercubeLabel = key( $network['axes'] );
			$axes = $network['axes'][ $hypercubeLabel ];
		}

		foreach ( $axes as $axisLabel => $axis)
		{
			if ( ! isset( $axis['dimension'] ) ) continue;

			// More than one member?
			if ( $hasMultipleMembers( $axes, $axis ) )
			{
				// If there is a contexts filter, use it to determine the correct member to use
				if ( ! $contextsFilter )
				{
					$contextsFilter = $instance->getContexts();
				}

				if ( $contextsFilter->NoSegmentContexts()->count() )
				{
					// Use the default member
					$memberLabel = $axis['default-member'];
				}
				else
				{
					// Use the member associated with one of the contexts
					$axisContexts = $contextsFilter->SegmentContexts( strstr( $axisLabel, '#' ) )->getContexts();
					if ( count( $axisContexts ) )
					{
						$axisContext = reset( $axisContexts );
						$segment = isset( $axisContext['entity']['segment'] )
							? $axisContext['entity']['segment']
							: ( isset( $axisContext['entity']['scenario'] )
								? $axisContext['entity']['scenario']
								: (isset( $axisContext['segment'] )
									? $axisContext['segment']
									: ( isset( $axisContext['scenario'] )
										? isset( $axisContext['scenario'] )
										: null
									)
								)
							);

						if ( ! $segment ) continue;

						$member = ( isset( $segment['explicitMember'] ) ? $segment['explicitMember'] : $segment['typedMember'] )[0];
						$memberQName = qname( $member['member'], $instance->getInstanceNamespaces() );

						if ( ! $memberQName )
						{
							echo "Unable to craete QName for member '$member'\n";
							continue;
						}

						$memberTaxonomy = $this->getTaxonomyForNamespace( $memberQName->namespaceURI );
						$memberElement = $memberTaxonomy->getElementByName( $memberQName->localName );

						$memberLabel = $memberTaxonomy->getTaxonomyXSD() . "#" . $memberElement['id'];
					}
					else
					{
						$memberLabel = $axis['default-member'];
					}
				}
			}
			else if ( ! ( isset( $axis['typedDomainRef'] ) && $axis['typedDomainRef'] ) )
			{
				/** @var QName $memberQName */
				$memberQName = reset( $axis['members'] );

				if ( ! $memberQName )
				{
					if ( ! $axis['default-member'] ) continue;
					$memberLabel = $axis['default-member'];
				}
				else
				{
					$memberTaxonomy = $this->getTaxonomyForNamespace( $memberQName->namespaceURI );
					$memberElement = $memberTaxonomy->getElementByName( $memberQName->localName );

					$memberLabel = $memberTaxonomy->getTaxonomyXSD() . "#" . $memberElement['id'];
				}
			}

			$dimensionText = $this->getTaxonomyDescriptionForIdWithDefaults( $axisLabel );
			$slicers .= "			<div class='slicer-header'>$dimensionText</div>";

			if ( isset( $axis['typedDomainRef'] ) && $axis['typedDomainRef'] )
			{
				$contexts = $contextsFilter->SegmentContexts( strstr( $axisLabel, '#' ) )->getContexts();
				$memberText = $axis['typedDomainRef'];
			}
			else
			{
				$memberText = $this->getTaxonomyDescriptionForIdWithDefaults( $memberLabel );
			}

			$slicers .= "			<div class='slicer-content'>$memberText</div>";
		}

		if ( $contextsFilter )
		{
			// Add the period dimension
			$slicers .=
			"			<div class='slicer-header'>Period [Axis]</div>" .
			"			<div class='slicer-content'>{$contextsFilter->getPeriodLabel()}</div>";
		}

		$slicers .=
			"		</div>" .
			"	</div>";

		return $slicers;
	}

	/**
	 * Renders the model structure table
	 * @param array $network
	 * @return string
	 */
	private function renderModelStructure( $network )
	{
		$structureTable =
			"	<div class='structure-table hide-section'>" .
			"		<div>Label</div>" .
			"		<div>Fact set type</div>" .
			"		<div>Report Element Class</div>" .
			"		<div>Period Type</div>" .
			"		<div>Balance</div>" .
			"		<div>Name</div>";

			$renderStructure = function( $nodes ) use( &$renderStructure )
			{
				$result = array();

				foreach( $nodes as $label => $node )
				{
					if ( ! isset( $node['modelType'] ) ) continue;

					/** @var XBRL $nodeTaxonomy */
					$nodeTaxonomy = $this->getTaxonomyForXSD( $label );
					$nodeElement = $nodeTaxonomy->getElementById( $label );

					$preferredLabels = isset( $node['preferredLabel'] ) ? array( $node['preferredLabel'] ) : null;
					// Do this because $label includes the preferred label roles and the label passed cannot include it
					$text = $nodeTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $nodeTaxonomy->getTaxonomyXSD() . '#' . $nodeElement['id'], $preferredLabels );

					$name = $nodeTaxonomy->getPrefix() . ":" . $nodeElement['name'];
					$class = "";
					$reportElement = "";
					$periodType = "";
					$balance = "";
					$factSetType = "";

					switch ( $node['modelType'] )
					{
						case 'cm.xsd#cm_Table':
							$class = "hypercube";
							$reportElement = "[Table]";
							break;

						case 'cm.xsd#cm_Axis':
							$class = "axis";
							$reportElement = "[Axis]";
							if ( $node['typedDomainRef'] ) $reportElement .= " ({$node['typedDomainRef']})";
							if ( $node['default-member'] )
							{
								/** @var XBRL $memberTaxonomy */
								$memberTaxonomy = $this->getTaxonomyForXSD( $node['default-member'] );
								$memberElement = $memberTaxonomy->getElementById( $node['default-member'] );
								$reportElement .= " ({$memberTaxonomy->getPrefix()}:{$memberElement['name']})";
							}
							break;

						case 'cm.xsd#cm_Member':
							$class = "member";
							$reportElement = "[Member]";
							if ( isset( $node['is-domain'] ) && $node['is-domain'] ) $reportElement .= " (domain)";
							if ( isset( $node['is-default'] ) && $node['is-default'] ) $reportElement .= " (default)";
							break;

						case 'cm.xsd#cm_LineItems':
							$class = "lineitem";
							$reportElement = "[Line item]";
							$factSetType = isset( $node['patterntype'] ) ? $node['patterntype'] : '';
							break;

						case 'cm.xsd#cm_Concept':
							$class = "concept";
							$reportElement = "[Concept]";
							if ( $nodeElement['type'] == 'xbrli:stringItemType' )
							{
								$reportElement .= " string";
							}
							else if ( $this->context->types->resolvesToBaseType( $nodeElement['type'], array( "xbrli:monetaryItemType" ) ) )
							{
								$reportElement .= " monetary";
							}
							else if ( $nodeElement['type'] == 'xbrli:sharesItemType' )
							{
								$reportElement .= " shares";
							}
							else
							{
								$reportElement .= " " . $nodeElement['type'];
							}
							$periodType = $nodeElement['periodType'];
							$balance = isset( $nodeElement['balance'] ) ? $nodeElement['balance'] : 'n/a';
							$factSetType = isset( $node['patterntype'] ) ? $node['patterntype'] : '';
							break;

						case 'cm.xsd#cm_Abstract':
							$class = "abstract";
							$reportElement = "[Abstract]";
							$factSetType = isset( $node['patterntype'] ) ? $node['patterntype'] : '';
							break;
					}

					$result[] = "<div><span class='depth{$node['depth']} $class'>$text</span></div>";
					$result[] = "<div>$factSetType</div>";
					$result[] = "<div>$reportElement</div>"; // This text should be based on some lookup
					$result[] = "<div>$periodType</div>";
					$result[] = "<div>$balance</div>";
					$result[] = "<div>$name</div>";

					if ( ! isset( $node['children'] ) || ! $node['children'] ) continue;
					$result = array_merge( $result, $renderStructure( $node['children'] ) );
				}

				return $result;
			};

			$result = $renderStructure( $network['hierarchy'] );

		$structureTable .= implode( '', $result ) . "	</div>";

		return $structureTable;
	}

	/**
	 * Render a report with columns for any years and dimensions
	 * @param array $network
	 * @param array $nodes The node hierarchy to report.  At the top level this will be $network['hierarchy']
	 * @param string $elr
	 * @param XBRL_Instance $instance
	 * @param QName $entityQName
	 * @param XBRL_Formulas $formulas	The evaluated formulas
	 * @param Observer $observer		An obsever with any validation errors
	 * @param $evaluationResults		The results of validating the formulas
	 * @return string
	 */
	private function renderReportTable( $network, $nodes, $elr, $instance, $entityQName, $formulas, $observer, $evaluationResults, &$resultFactsLayout, $accumulatedTables, $nodesToProcess, $lineItems, $excludeEmptyHeadrers, &$row, $lasts, $parentLabel = null )
	{
		$axes = XBRL::array_reduce_key( $network['axes'], function( $carry, $axes, $hypercube ) use ( &$accumulatedTables )
		{
			if ( ! in_array( $hypercube, $accumulatedTables ) ) return $carry;
			return array_merge( $carry, $axes );
		}, [] );

		// Get a list of the nodes representing concepts that have the same dimensionality
		// That is, ignore sub-nodes and their descendants that are associated with aother hypercube
		$getDimensionalNodes = function( $nodes ) use( &$getDimensionalNodes, &$network, &$accumulatedTables )
		{
			$result = array();

			foreach ( $nodes as $label => $node )
			{
				if ( $node['modelType'] == 'cm.xsd#cm_Axis' ) continue;

				if ( isset( $node['preferredLabel'] ) && $node['preferredLabel'] )
				{
					/** @var XBRL $nodeTaxonomy */
					$nodeTaxonomy = $this->getTaxonomyForXSD( $label );
					$nodeElement = $nodeTaxonomy->getElementById( $label );

					$label = $nodeTaxonomy->getTaxonomyXSD() . '#' . $nodeElement['id'];
				}

				if ( isset( $network['tables'][ $label ] ) && ! isset( $accumulatedTables[ $label ] ) )
				{
					continue;
				}

				if ( isset( $network['concepts'][ $label ] ) )
				{
					$result[ $label ] = $network['concepts'][ $label ];
				}
				if ( ! isset( $node['children'] ) ) continue;
				$result = array_merge( $result, $getDimensionalNodes( $node['children'] ) );
			}

			return $result;
		};

		$dimensionalNodes = $getDimensionalNodes( $nodes );

		// Get the names of the concepts used by this view (excluding ones in sub-reports)
		$names = array_map( function( $conceptQName )
		{
			return $conceptQName->localName;
		}, $dimensionalNodes );

		// Use the names to return a list of the facts
		$elements = $instance->getElements()->ElementsByName( $names )->getElements();

		// Next, create a list of the context refs used
		$contextRefs = array_reduce( $elements, function( $carry, $element ) use ( $instance )
		{
			$result = array_unique( array_map( function( $fact ) { return $fact['contextRef']; }, array_values( $element ) ) );
			return array_unique( array_merge( $carry, $result ) );
		}, array() );

		// And, so, a list of contexts
		$rawContexts = array_intersect_key( $instance->getContexts()->getContexts(), array_flip( $contextRefs ) );

		// Filter contexts to just those used by an axis or default members
		$cf = new ContextsFilter( $instance, $rawContexts );
		// Context without a segment are always allowed because they will be used by default members
		$contexts = $cf->NoSegmentContexts()->getContexts();
		// Select dimension specific contexts
		foreach ( $axes as $axisLabel => $axis )
		{
			if ( ! isset( $axis['dimension'] ) ) continue;
			$dimTaxonomy = $instance->getInstanceTaxonomy()->getTaxonomyForXSD( $axisLabel );
			$axisContexts = $cf->SegmentContexts( strstr( $axisLabel, '#' ), $dimTaxonomy->getNamespace() );
			$contexts = array_merge( $contexts, $axisContexts->getContexts() );
		}

		// Use the remaining contexts to return a list the applicable years
		$years = array();
		foreach ( $contexts as $contextRef => $context )
		{
			$year = substr( $context['period']['endDate'], 0, 4 );
			if ( ! isset( $years[ $year ] ) ) $years[ $year ] = array(
				'text' => $context['period']['is_instant']
								? $context['period']['endDate']
								: "{$context['period']['startDate']} - {$context['period']['endDate']}",
				'contextRefs' => array(),
				// 'year' => $year
			);
			$years[ $year ]['contextRefs'][] = $contextRef;
		}

		// Present years in a consistent order - most recent first
		krsort( $years );

		$totalAxesCount = array_reduce( $axes, function( $carry, $axis ) { return $carry + ( isset( $axis['dimension'] ) ? 1 : 0 ) ; } );

		$hasReportDateAxis = false;
		// Get a list of dimensions with more than one member
		$multiMemberAxes = array_reduce( array_keys( $axes ), function( $carry, $axisLabel ) use( $axes, $instance, &$hasReportDateAxis )
		{
			/** @var XBRL $taxonomy */
			$taxonomy = $instance->getInstanceTaxonomy()->getTaxonomyForXSD( $axisLabel );
			$element = $taxonomy->getElementById( $axisLabel );

			$axis = $axes[ $axisLabel ];

			if ( ! isset( $axis['dimension'] ) || // Ignore member only items
				 (
				   count( $axis['members'] ) <= 1 && // Ignore axes with more than one member
				   ! isset( $axes[ key( $axis['members'] ) ] ) // Or that has sub-members
				 )
			) return $carry;

			if ( in_array( $element['name'], $this->axesToExclude ) )
			{
				if ( in_array( $element['name'], $this->reportDateAxisAliases ) )
				{
					// Must be more than one context
					if ( $instance->getContexts()->SegmentContexts( strstr( $axisLabel, '#' ), $taxonomy->getNamespace() )->count() > ( $axis['default-member'] ? 0 : 1 ) )
					{
						$hasReportDateAxis = $axisLabel;
					}
				}
				return $carry; // ReportDateAxis is not reported as a column
			}

			// Exclude axes without contexts with any of their members
			if ( $instance->getContexts()->SegmentContexts( strstr( $axisLabel, '#' ), $taxonomy->getNamespace() )->count() == 0 )
			{
				return $carry;
			}

			$carry[] = $axisLabel;
			return $carry;
		}, array() );

		// Add any multi-member typed domains
		foreach ( $axes as $axisLabel => $axis )
		{
			if ( ! isset( $axis['dimension'] ) ) continue;

			// Find any of the dimensions that are typed domains
			if ( ! $axis['typedDomainRef'] ) continue;
			$dimTaxonomy = $instance->getInstanceTaxonomy()->getTaxonomyForXSD( $axisLabel );
			$axisContexts = $cf->SegmentContexts( strstr( $axisLabel, '#' ), $dimTaxonomy->getNamespace() );
			if ( $axisContexts->count() <= 1 ) continue;
			$multiMemberAxes[] = $axisLabel;

			$label = $axis['typedDomainRef'];
			$memTaxonomy = $dimTaxonomy;
			if ( $label[0] != '#' )
			{
				$memTaxonomy = $dimTaxonomy->getTaxonomyForXSD( $label );
			}

			$memElement = $memTaxonomy->getElementById( $label );
			$memQName = "{$memTaxonomy->getPrefix()}:{$memElement['name']}";

			foreach ( $axisContexts->getContexts() as $contextRef => $context )
			{
				$segment = $instance->getContextSegment( $context );
				$typedDomain = reset( $segment['typedMember'] ); // Probably should search for the one with the dimension label
				$memberText = preg_replace( array( "!<$memQName>!", "!</$memQName>!" ), "", reset( $typedDomain['member'][ $memQName ] ) );
				$axes[ $axisLabel ]['typedDomainMembers'][ $memberText ] = $contextRef;
			}
		}

		// Get count of dimensions with more than one member
		$multiMemberAxesCount = count( $multiMemberAxes );

		// If there are single member axes then remove contexts that are not related to the single member
		$singleMemberAxes = array_diff_key( $axes, array_flip( $multiMemberAxes ) );
		$cf = new ContextsFilter( $instance, $contexts );
		foreach ( $singleMemberAxes as $axisLabel => $axis )
		{
			if ( $axisLabel == $hasReportDateAxis ) continue;
			if ( ! isset( $axis['dimension'] ) ) continue;
			// Ignore typed domain members as they have only non-default members
			if ( isset( $axis['typedDomainRef'] ) && $axis['typedDomainRef'] ) continue;

			// As it's a single member axis it may be because there is only one member in which case
			// use the key function  to retrieve the only member label.  Alternatively it may be that
			// there are multiple members but the data only uses the default.
			$memberLabel = $axis['default-member'] ? $axis['default-member'] : key( $axis['members'] );

			$dimTaxonomy = $instance->getInstanceTaxonomy()->getTaxonomyForXSD( $axisLabel );
			// If the member is default then the context will be added
			// but there cant be any other members
			if ( ( is_null( $memberLabel ) && $axis['default-member'] ) || $memberLabel == $axis['default-member'] )
			{
				$segmentContexts = $cf->SegmentContexts( strstr( $axisLabel, '#' ), $dimTaxonomy->getNamespace() );
				if ( ! $segmentContexts->count() ) continue;
				$cf->remove( $segmentContexts );
			}
			else
			{
				// If not then select only contexts with the member
				$memTaxonomy = $dimTaxonomy->getTaxonomyForXSD( $memberLabel );
				$cf = $cf->SegmentContexts( strstr( $axisLabel, '#' ), $dimTaxonomy->getNamespace(), strstr( $memberLabel, '#' ), $memTaxonomy->getNamespace() );
			}
		}

		$contexts = $cf->getContexts();
		// Should report here that there are no contexts
		if ( ! $contexts )
		{
			return '';
			return
				"	<div style='display: grid; grid-template-columns: 1fr; '>" .
				"		<div style='display: grid; grid-template-columns: auto 1fr;'>" .
				"			There is no data to report " . ( $parentLabel ? " for '$parentLabel'" : '' ) .
				"		</div>" .
				"	</div>";
		}

		// The number of columns is the number of $years * the number of members for each dimension
		$headerColumnCount = count( $years );

		$getAxisMembers = function( $members ) use( &$getAxisMembers, &$axes )
		{
			$result = array();
			foreach ( $members as $memberLabel => $memberQName )
			{
				$result[] = $memberLabel;
				if ( ! isset( $axes[ $memberLabel ] ) ) continue;
				$result = array_merge( $result, $getAxisMembers( $axes[ $memberLabel ]['members'] ) );
			}

			// Move the first element to the end
			return count( $result ) == 1
				? $result
				: array_merge( array_slice( $result, 1 ), array_slice( $result, 0, 1 ) );
			// return array_reverse( $result );
		};

		foreach ( $multiMemberAxes as $axisLabel )
		{
			$headerColumnCount *= isset( $axes[ $axisLabel ]['typedDomainMembers'] ) && $axes[ $axisLabel ]['typedDomainMembers']
				? count( $axes[ $axisLabel ]['typedDomainMembers'] )
				: count( $getAxisMembers( $axes[ $axisLabel ]['members'] ) );
		}

		$getRowCount = function( $nodes, $lineItems = false ) use( &$getRowCount, $instance )
		{
			$count = 0;
			foreach ( $nodes as $label => $node )
			{
				$lineItems |= $node['modelType'] == 'cm.xsd#cm_LineItems';
				if ( $lineItems )
				{
					$count++;
				}

				if ( ! isset( $node['children'] ) || ! $node['children'] ) continue;

				$count += $getRowCount( $node['children'], $lineItems );
			}

			return $count;
		};

		$rowCount = $getRowCount( $nodes );

		// The final row count has to include the number of multi-member axes * 2 + 1.
		// The 2 because each axis contributes two header rows: for the dimension label and for the member
		// The 1 is from the period axis but the row count already has a line from the header: the line items row
		$rowCount += $multiMemberAxesCount * 2 + 1;

		// How many are header rows?
		// The one is for the implicit period axis.
		$headerRowCount = ($multiMemberAxesCount + 1) * 2;

		// Workout what the columns will contain. $columnHierarchy will contain a hierarchical list of nodes
		// where the leaf nodes represent the actual columns and there should be $headerColumnCount of them.
		// Each node will contain a list of the axis/members and a list of contexts which apply at that node.
		// Until there is a more complete example
		$columnHierarchy['Period [Axis]'] = $years;
		$columnHierarchy['Period [Axis]']['total-children'] = count( $years );

		// Extend $columnHierarchy to add columns for $multiMemberAxes and their members
		if ( $multiMemberAxes )
		{
			$addToColumnHierarchy = function( &$columnHierarchy, $multiMemberAxes ) use ( &$addToColumnHierarchy, $instance, &$axes, &$getAxisMembers )
			{
				$totalChildren = 0;

				foreach ( $columnHierarchy as $axisLabel => &$members )
				{
					if ( ! $multiMemberAxes )
					{
						$totalChildren += $members['total-children'];
						continue;
					}

					foreach ( $members as $index => &$member )
					{
						if ( $index == 'total-children' ) continue;

						if ( isset( $member['children'] ) )
						{
							$totalChildren += $addToColumnHierarchy( $member['children'], $multiMemberAxes );
						}
						else
						{
							// Get the axis text
							$nextAxisLabel = reset( $multiMemberAxes );
							/** @var XBRL_DFR $axisTaxonomy */
							$axisTaxonomy = $this->getTaxonomyForXSD( $nextAxisLabel );
							$axisText = $axisTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $nextAxisLabel );

							// Get the members
							$axis = $axes[ $nextAxisLabel ];
							$nextMembers = array();

							if ( isset( $axis['typedDomainMembers'] ) )
							{
								if ( count( $member['contextRefs'] ) != count( $axis['typedDomainMembers'] ) )
								{
									XBRL_Log::getInstance()->warning( "addToColumnHierarchy: The number of contexts in member label '$nextAxisLabel' does not equal the number of typed domain members" );
								}

								foreach ( $axis['typedDomainMembers'] as $memberText => $contextRef )
								{
									$guid = XBRL::GUID();
									$nextMembers[ $guid ] = array(
										'text' => $memberText,
										'contextRefs' => array( $contextRef ),
										'default-member' => false,
										'domain-member' => false,
										'root-member' => false
									);
								}
							}
							else
							{
								$axisMembers = $getAxisMembers( $axes[ $nextAxisLabel ]['members'] );

								// Workout which contexts apply
								$cf = new ContextsFilter( $instance, array_reduce( $member['contextRefs'], function( $carry, $contextRef ) use( $instance) { $carry[ $contextRef ] = $instance->getContext( $contextRef ); return $carry; }, array() ) );

								foreach ( $axisMembers as $memberLabel )
								{
									/** @var XBRL_DFR $memberTaxonomy */
									$memberTaxonomy = $this->getTaxonomyForXSD( $memberLabel );
									$memberText = $memberTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $memberLabel );

									$filteredContexts = $axis['default-member'] == $memberLabel
										? $cf-> NoSegmentContexts()
										: $cf->SegmentContexts( strstr( $nextAxisLabel, '#' ), $axisTaxonomy->getNamespace(), strstr( $memberLabel, '#' ), $memberTaxonomy->getNamespace() );

									if ( $filteredContexts->count() )
									{
										$guid = XBRL::GUID();
										$nextMembers[ $guid ] = array(
											'text' => $memberText,
											'contextRefs' => array_keys( $filteredContexts->getContexts() ),
											'default-member' => $axis['default-member'] == $memberLabel,
											'domain-member' => $axis['domain-member'] == $memberLabel,
											'root-member' => $axis['root-member'] == $memberLabel
										);
									}
								}
							}

							$nextMembers['total-children'] = count( $nextMembers );
							$member['children'][ $axisText ] = $nextMembers;

							// if ( count( $multiMemberAxes ) == 1 ) continue;

							$totalChildren += $addToColumnHierarchy( $member['children'], array_slice( $multiMemberAxes, 1 ) );
							// $nextMembers['total-children'] = $totalChildren;
							// unset( $nextMembers );
						}
					}
					unset( $member );
				}

				$members['total-children'] = $totalChildren;

				unset( $members );

				return $totalChildren;
			};

			$headerColumnCount = $addToColumnHierarchy( $columnHierarchy, $multiMemberAxes );
		}

		$columnCount = $headerColumnCount + 1 + ( $hasReportDateAxis ? 1 : 0 ); // Add the description column

		// Create an index of contextRef to column.  Should be only one column for each context.
		// At the same time create a column layout array that can be used to generate the column headers
		$columnLayout = array();
		$createContextRefColumns = function( $columnNodes, $depth = 0 ) use( &$createContextRefColumns, &$columnLayout )
		{
			$result = array();
			foreach ( $columnNodes as $axisLabel => $columnMembers )
			{
				$details = array( 'text' => $axisLabel, 'span' => $columnMembers['total-children'] );
				$columnLayout[ $depth ][] = $details;

				foreach ( $columnMembers as $index => $columnNode )
				{
					if ( $index == 'total-children' ) continue;

					$span = isset( $columnNode['children'] )
						? array_reduce( $columnNode['children'], function( $carry, $axis ) { return $carry + $axis['total-children']; }, 0 )
						: 1;
					$columnLayout[ $depth + 1 ][] = array(
						'text' => $columnNode['text'],
						'span' => $span,
						'default-member' => isset( $columnNode['default-member'] ) && $columnNode['default-member'],
						'domain-member' => isset( $columnNode['domain-member'] ) && $columnNode['domain-member'],
						'root-member' => isset( $columnNode['root-member'] ) && $columnNode['root-member']
					);

					if ( isset( $columnNode['children'] ) && $columnNode['children'] )
					{
						$result += $createContextRefColumns( $columnNode['children'], $depth + 2 );
					}
					else if ( $columnNode['contextRefs'] )
					{
						$result = array_merge( $result, array_fill_keys( $columnNode['contextRefs'], $index ) );
						// $result += $columnNode['contextRefs'] ? array_fill_keys( $columnNode['contextRefs'], $index ) : array( 'place_holder_' . XBRL::GUID() => $index );
					}
					else
					{
						// This is necessary so the $columnsRef array will be created with the correct column offsets
						$result = array_merge( $result, array( 'place_holder_' . XBRL::GUID() => $index ) );
					}
				}
			}
			return $result;
		};
		$contextRefColumns = $createContextRefColumns( $columnHierarchy );
		$columnRefs = array_flip( array_values( array_unique( $contextRefColumns ) ) );

		if ( count( $columnLayout ) != $headerRowCount )
		{
			$generatedHeaderRows = count( $columnLayout );
			XBRL_Log::getInstance()->warning( "The number of header rows generated ($generatedHeaderRows) does not equal the number of row expected ($headerRowCount)" );
		}

		$getFactSetTypes = function( $nodes, $lineItems = false ) use( &$getFactSetTypes, $instance )
		{
			$factSetTypes = array();

			foreach ( $nodes as $label => $node )
			{
				$abstractLineItems = $node['modelType'] == 'cm.xsd#cm_Abstract';
				$thisLineItems = $node['modelType'] == 'cm.xsd#cm_LineItems';
				$lineItems |= $thisLineItems | $abstractLineItems;

				// $thisLineItems = $node['modelType'] == 'cm.xsd#cm_LineItems';
				// $lineItems |= $thisLineItems;
				if ( $lineItems && ( $thisLineItems || $node['modelType'] == 'cm.xsd#cm_Abstract' ) )
				{
					$factSetTypes[ $label ] = isset( $node['patterntype'] ) ? $node['patterntype'] : 'set';
				}

				if ( ! isset( $node['children'] ) || ! $node['children'] ) continue;

				$factSetTypes = array_merge( $factSetTypes, $getFactSetTypes( $node['children'], $lineItems ) );
			}

			return $factSetTypes;
		};

		$factSetTypes = $getFactSetTypes( $nodes );

		$removeColumn = function( &$axis, $columnId ) use( &$removeColumn )
		{
			foreach ( $axis as $axisId => &$columns )
			{
				if ( $axisId == 'total-children ') continue;

				if ( isset( $columns[ $columnId ] ) )
				{
					unset( $columns[ $columnId ] );
					$columns['total-children']--;
					if ( ! $columns['total-children'] )
					{
						unset( $axis[ $axisId ] );
					}
					return 1;
				}

				foreach ( $columns as $id => &$column )
				{

					if ( isset( $column['children'] ) )
					{
						$result = $removeColumn( $column['children'], $columnId );
						if ( $result )
						{
							$columns['total-children']--;
							if ( ! count( $column['children'] ) )
							{
								unset( $columns[ $id ] );
							}
							return 1;
						}
					}
				}
			}

			return 0;
		};

		/**
		 * Return the fact corresponding to the originally stated or restated condition
		 * @param XBRL_DFR $nodeTaxonomy
		 * @param array $facts (ref)
		 * @param array $axis an entry for an axis in $axes
		 * @param ContextsFilter $cf A filter of instant contexts
		 * @param bool $originally True gets the facts for the orginally stated case; false restated
		 * @var callable $getStatedFacts
		 * @var bool $hasReportDateAxis
		 */
		$getStatedFacts = function( $nodeTaxonomy, &$facts, &$axis, /** @var ContextsFilter $cf */ $cf, $originally = false )
			use ( &$getStatedFacts, $hasReportDateAxis )
		{
			// !! This is a spepcial case and there will be only one prior value not prior values for several previuous years

			// The opening balance value is the one that has a context with the non-default/non-domain member
			$members = array_reduce( $axis['members'], function( $carry, $memberQName ) use( &$axis, $nodeTaxonomy ) {
				$memberTaxonomy = $nodeTaxonomy->getTaxonomyForPrefix( $memberQName->prefix );
				$memberElement = $memberTaxonomy->getElementByName( $memberQName->localName );
				$memberLabel = $memberTaxonomy->getTaxonomyXSD() . "#" . $memberElement['id'];
				if ( $memberLabel == $axis['default-member'] || $memberLabel == $axis['domain-member'] || $memberLabel == $axis['root-member'] )
				{
					$carry[] = $memberLabel;
				}
				return $carry;
			}, array() );
			// For now assume there is only one
			$memberLabel = reset( $members );
			// Find the context(s)
			$reportDateAxisTaxonomy = $nodeTaxonomy->getTaxonomyForXSD( $hasReportDateAxis );
			$memberTaxonomy = $nodeTaxonomy->getTaxonomyForXSD( $memberLabel );
			$filteredContexts = $axis['default-member']
				? $cf->NoSegmentContexts()
				: $cf->SegmentContexts( strstr( $hasReportDateAxis, '#' ), $reportDateAxisTaxonomy->getNamespace(), strstr( $memberLabel, '#' ), $memberTaxonomy->getNamespace() );

			$contextRefs = array_keys( $filteredContexts->getContexts() );

			// Find the fact WITHOUT this context
			$cbFacts = $facts;
			$result = array();
			foreach ( $cbFacts as $factIndex => $fact )
			{
				if ( $originally ? in_array( $fact['contextRef'], $contextRefs ) : ! in_array( $fact['contextRef'], $contextRefs ) ) continue;
				$result[ $factIndex ] = $fact;
			}

			return $result;
		};

		// Now workout the facts layout.
		// Note to me.  This is probably the way to go as it separates the generation of the facts from the rendering layout
		// Right now it is used to drop columns
		$getFactsLayout = function( $nodes, $contexts, $parentLabel = null, $parentPattern = 'set', $lineItems = false )
			use( &$getFactsLayout, &$getStatedFacts, $instance, &$axes, &$network,
				 $columnLayout, $columnRefs, $contextRefColumns,
				 $elr, $hasReportDateAxis, $nodesToProcess, &$accumulatedTables )
		{
			$rows = array();
			$priorRowContextRefsForByColumns = array();

			$firstRow = reset( $nodes );
			$lastRow = end( $nodes );

			foreach ( $nodes as $label => $node )
			{
				if ( $nodesToProcess && ! in_array( $label, $nodesToProcess ) ) continue;

				$first = $node == $firstRow;
				$last = $node == $lastRow;

				$abstractLineItems = $node['modelType'] == 'cm.xsd#cm_Abstract';
				$thisLineItems = $node['modelType'] == 'cm.xsd#cm_LineItems';
				$lineItems |= $thisLineItems | $abstractLineItems;
				if ( $lineItems )
				{
					// Skip nodes in sub-reports
					if ( isset( $network['tables'][ $label ] ) && ! isset( $accumulatedTables[ $label ] ) )
					{
						continue;
					}

					/** @var XBRL_DFR $nodeTaxonomy */
					$nodeTaxonomy = $this->getTaxonomyForXSD( $label );
					$nodeElement = $nodeTaxonomy->getElementById( $label );

					if ( $thisLineItems )
					{
					}
					else if ( $node['modelType'] == 'cm.xsd#cm_Abstract' )
					{
					}
					else if ( isset( $nodeElement['abstract'] ) && $nodeElement['abstract'] )
					{
					}
					else
					{
						// Add the data.  There is likely to be only a partial facts set
						$facts = $instance->getElement( $nodeElement['name'] );
						// Filter facts by contexts
						$facts = array_filter( $facts, function( $fact ) use ( $contexts ) { return isset( $contexts[ $fact['contextRef'] ] ); } );

						// echo count( $facts ) . " $label\n";

						if ( $first && isset( $node['preferredLabel'] ) )
						{
							$openingBalance = $node['preferredLabel'] == XBRL_Constants::$labelRolePeriodStartLabel;
							$cf = new ContextsFilter( $instance, $contexts );
							/** @var ContextsFilter $instantContextsFilter */
							$instantContextsFilter = $cf->InstantContexts();

							if ( $hasReportDateAxis ) // && $node['preferredLabel'] == self::$originallyStatedLabel )
							{
								// If there is domain or default member of ReportDateAxis then one approach
								// is taken to find an opening balance.  If not the another approach is required.
								$axis = $axes[ $hasReportDateAxis ];
								if ( $axis['default-member'] || $axis['domain-member'] || $axis['root-member'] )
								{
									$facts = $getStatedFacts( $nodeTaxonomy, $facts, $axis, $instantContextsFilter, true );

									// There will be one column here so insert the appropriate context
									$columnId = key( $columnRefs );
									$candidates = array_filter( $contextRefColumns, function( $id ) use( $columnId ) { return $id == $columnId; } );
									$context = array_intersect_key( $instantContextsFilter->getContexts(), $candidates );
									if ( $context )
									{
										// Need to retain the the original context so the correct text for the adjustment reported date columns can be retrieved
										// This will be used to replace the contextRef when the text has been retrieved.
										$facts[ key( $facts ) ]['contextRefRestated'] = key( $context );
									}
								}
								else
								{
									$openingBalance = true;
								}
							}

							if ( $openingBalance )
							{
								$cbFacts = $facts;
								$facts = array();
								foreach ( $cbFacts as $cbFactIndex => $cbFact )
								{
									/** @var ContextsFilter $segmentContextFilter */
									$segmentContextFilter = $instantContextsFilter->SameContextSegment( $contexts[ $cbFact['contextRef'] ] );
									$segmentContexts = $segmentContextFilter->sortByEndDate()->getContexts();

									// Find the fact's prior context
									reset( $segmentContexts );
									do
									{
										if ( key( $segmentContexts ) != $cbFact['contextRef'] ) continue;
										next( $segmentContexts );
										break;
									}
									while ( next( $segmentContexts ) );

									if ( is_null( $contextRef = key( $segmentContexts ) ) ) continue;

									// Find the fact with this context
									foreach ( $cbFacts as $factIndex => $fact )
									{
										if ( $fact['contextRef'] != $contextRef ) continue;
										if ( ! $hasReportDateAxis )
										{
											$fact['contextRef'] = $cbFact['contextRef'];
										}
										$facts[ $factIndex ] = $fact;
										break;
									}
								}
								unset( $cbFacts );
							}
						}

						$columns = array();

						// Look for the fact with $contextRef
						if ( $hasReportDateAxis && $last )
						{
							$axis = $axes[ $hasReportDateAxis ];
							// Find the segment with $hasReportDateAxis
							if ( isset( $node['preferredLabel'] ) ) // && $node['preferredLabel'] == XBRL_Constants::$labelRoleRestatedLabel )
							{
								if ( $axis['default-member'] || $axis['domain-member'] || $axis['root-member'] )
								{
									$cf = new ContextsFilter( $instance, $contexts );
									/** @var ContextsFilter $instantContextsFilter */
									$instantContextsFilter = $cf->InstantContexts();
									$facts = $getStatedFacts( $nodeTaxonomy, $facts, $axis, $instantContextsFilter, false );
									// $fact = reset( $facts );
								}
								else
								{
									$fact = reset( $facts ); // By default use the first fact
									if ( count( $facts ) > 1 && $priorRowContextRefsForByColumns )
									{
										$contextRef = reset( $priorRowContextRefsForByColumns );
										// Look for a fact with this context ref
										$f = @reset( array_filter( $facts, function( $fact ) use ( $contextRef ) { return $fact['contextRef'] == $contextRef ; } ) );
										if ( $f ) $fact = $f;
									}
								}
							}
						}

						$priorRowContextRefsForByColumns = array();

						$lastRowLayout = end( $columnLayout );

						$rollupTotal = false;
						if ( $parentPattern == 'rollup' && isset( $this->calculationNetworks[ $elr ]['calculations'][ $node['label'] ] ) )
						{
							$rollupTotal = true;

							// Add up the fact values taking into account the weight and balance
							$rollupTotals = array();
							foreach ( $nodes as $rollupLabel => $rollupNode )
							{
								if ( $rollupLabel == $label || ! isset( $rows[ $rollupLabel ] ) ) continue;
								$row =& $rows[ $rollupLabel ];
								foreach ( $row['columns'] as $columnIndex => $column )
								{
									$rollupItemValue = $instance->getNumericPresentation( $column ) * ( $nodeElement['balance'] == $row['element']['balance'] ? 1 : -1 );
									$rollupTotals[ $columnIndex] = (
										isset( $rollupTotals[ $columnIndex] )
											? $rollupTotals[ $columnIndex]
											: 0
										) + $rollupItemValue;

									unset( $rollupItemValue );
								}
								unset( $columnIndex );
								unset( $column );
								unset( $row );
							}
							unset( $rollupNode );
							unset( $rollupLabel );

							$calculation =& $this->calculationNetworks[ $elr ]['calculations'][ $node['label'] ];
							$calculationTotals = array();
							foreach ( $calculation as $calcItemLabel => $calcItem )
							{
								$calcTaxonomy = $this->getTaxonomyForXSD( $calcItemLabel );
								$calcElement = $calcTaxonomy->getElementById( $calcItemLabel );
								$calcFacts = $instance->getElement( $calcElement['name'] );
								foreach ( $calcFacts as $calcFact )
								{
									$rollupItemValue = $instance->getNumericPresentation( $calcFact ) * ( $nodeElement['balance'] == $calcElement['balance'] ? 1 : -1 );
									$calculationTotals[ $calcFact['contextRef'] ] = (
										isset( $calculationTotals[ $calcFact['contextRef'] ] )
											? $calculationTotals[ $calcFact['contextRef'] ]
											: 0
										) + $rollupItemValue;

									unset( $rollupItemValue );
								}
								unset( $calcTaxonomy );
								unset( $calcElement );
								unset( $calcFacts );
								unset( $calcFact );
							}
							unset( $calculation );
							unset( $calcItem );
							unset( $calcItemLabel );
						}


						foreach ( $facts as $factIndex => $fact )
						{
							if ( ! $fact || ! isset( $contextRefColumns[ $fact['contextRef'] ] ) ) continue;
							$columnIndex = $columnRefs[ $contextRefColumns[ $fact['contextRef'] ] ];
							// Check that the column is still reportable.  It might have been removed as empty
							if ( ! isset( $lastRowLayout[ $columnIndex ] ) ) continue;
							$currentColumn = $lastRowLayout[ $columnIndex ];

							$columns[ $columnIndex ] = $fact;
							$priorRowContextRefsForByColumns[ $columnIndex ] = $fact['contextRef'];

							if ( $rollupTotal )
							{
								$sum = isset( $rollupTotals[ $columnIndex ] ) ? $rollupTotals[ $columnIndex ] : 0;
								$inferredDecimals = $instance->getDecimals( $fact );
								$rollupTotals[ $columnIndex ] = is_infinite( $inferredDecimals ) ? $sum : round( $sum, $instance->getDecimals( $fact ), PHP_ROUND_HALF_EVEN );

								if ( isset( $calculationTotals[ $fact['contextRef'] ] ) )
								{
									$calculationTotals[ $columnIndex ] = $calculationTotals[ $fact['contextRef'] ];
									unset( $calculationTotals[ $fact['contextRef'] ] );
								}
							}
						}
						unset( $fact ); // Gets confusing having old values hanging around
						unset( $facts );

						ksort( $columns );
						$rows[ $label ] = array( 'columns' => $columns, 'taxonomy' => $nodeTaxonomy, 'element' => $nodeElement, 'node' => $node );
						if ( $rollupTotal )
						{
							$rows[ $label ]['rollupTotals'] = $rollupTotals;
							$rows[ $label ]['calcTotals'] = $calculationTotals;
							if ( $parentLabel )
							{
								$rows[ $parentLabel ] = $rows[ $label ];
							}
							unset( $calculationTotals );
							unset( $rollupTotals );
						}
						unset( $columns );
					}
				}

				if ( ! isset( $node['children'] ) || ! $node['children'] ) continue;

				$rows = array_merge( $rows, $getFactsLayout( $node['children'], $contexts, $label, isset( $node['patterntype'] ) ? $node['patterntype'] : $parentPattern, $lineItems ) );
			}

			return $rows;
		};

		// Create a set of labels of the sub-nodes
		$getSubNodeLabels = function( $nodes ) use( &$getSubNodeLabels )
		{
			$result = array();
			foreach ( $nodes as $nodeLabel => $node )
			{
				if ( $node['modelType'] == 'cm.xsd#cm_Concept' )
				{
					$result[] = $nodeLabel;
				}
				if ( ! isset( $node['children'] ) ) continue;
				$result = array_merge( $result, $getSubNodeLabels( $node['children'] ) );
			}
			return $result;
		};

		$factsLayout = array_filter( $getFactsLayout( $nodes, $contexts, null, 'set', $lineItems ), function( $node )
		{
			return count( $node['columns'] );
		} );

		if ( ! $factsLayout ) // Not sure this is required
		{
			return "	<div style='display: grid; grid-template-columns: 1fr; '>" .
				"		<div style='display: grid; grid-template-columns: auto 1fr;'>" .
				"			There is no data to report" .
				"		</div>" .
				"	</div>";
		}

		// Update the last entry with details of the current report
		end( $resultFactsLayout );
		$resultFactsLayout[ key( $resultFactsLayout ) ] = array(
			'axes' => array_keys( array_filter( $axes, function( $axis ) { return isset( $axis['dimension'] ); } ) ),
			'data' => $factsLayout
		);

		$dropEmptyColumns = function( $rows, &$columnHierarchy, &$columnCount, &$headerColumnCount, &$columnRefs, &$columnLayout, $foundDroppableTypes ) use( &$createContextRefColumns, $hasReportDateAxis, &$removeColumn )
		{
			$columnsToDrop = array();
			$flipped = array_flip( $columnRefs );
			for ( $columnIndex = 0; $columnIndex<$headerColumnCount; $columnIndex++ )
			{
				$empty = true;
				$firstRow = reset( $rows );
				$lastRow = end( $rows );

				foreach ( $rows as $label => $row )
				{
					if ( $row['element']['periodType'] == 'instant' && isset( $row['node']['preferredLabel'] ) )
					{
						$preferredLabel = $row['node']['preferredLabel'];
						$balanceItem =	$preferredLabel == XBRL_Constants::$labelRolePeriodEndLabel ||
										$preferredLabel == XBRL_Constants::$labelRolePeriodStartLabel ||
										// $preferredLabel == XBRL_Constants::$labelRoleRestatedLabel ||
										// $preferredLabel == self::$originallyStatedLabel ||
										$hasReportDateAxis && ( $row == $firstRow || $row == $lastRow ) ;
						if ( $balanceItem ) continue;  // Ignore closing balance
					}
					if ( ! isset( $row['columns'][ $columnIndex ] ) ) continue;

					$empty = false;
					break;
				}
				if ( $empty ) $columnsToDrop[] = $flipped[ $columnIndex ];
			}

			foreach ( $columnsToDrop as $columnIndex )
			{
				$removeColumn( $columnHierarchy, $columnIndex );
				$headerColumnCount--;
				$columnCount--;
				$columnLayout = array();
				$contextRefColumns = $createContextRefColumns( $columnHierarchy );
				$columnRefs = array_flip( array_values( array_unique( $contextRefColumns ) ) );
			}

		};

		$droppableTypesList = array( 'adjustment', 'rollforward', 'rollforwardinfo', 'set' );
		$foundDroppableTypes = array_filter( $factSetTypes, function( $type ) use ( $droppableTypesList ) { return in_array( $type, $droppableTypesList ); } );
		if ( $foundDroppableTypes )
		{
			$dropEmptyColumns( $factsLayout, $columnHierarchy, $columnCount, $headerColumnCount, $columnRefs, $columnLayout, $foundDroppableTypes );
		}

		// Generate a top for the report table
		$top = function( $reportDateColumn, $headerColumnCount, $columnWidth ) use ( &$top )
		{
			return
				"	<div class='report-section' style='display: grid; grid-template-columns: 1fr; '>" .
				"		<div style='display: grid; grid-template-columns: auto 1fr;'>" .
				"			<div class='report-table-controls'>" .
				"				<div class='control-header'>Columns:</div>" .
				"				<div class='control-wider'>&lt;&nbsp;Wider&nbsp;&gt;</div><div>|</div>" .
				"				<div class='control-narrower'>&gt;&nbsp;Narrower&nbsp;&lt;</div>" .
				"			</div><div></div>" .
				"			<div class='report-table' style='display: grid; grid-template-columns: 400px $reportDateColumn repeat( $headerColumnCount, $columnWidth ); grid-template-rows: repeat(10, auto);' >";
		};

		// Generate a tail for the report table
		$tail = function( &$footnotes, $hasReportDateAxis, $headerColumnCount ) use( &$tail )
		{
			$reportTail =
				"				<div class='report-line line-item abstract final'></div>";

			if ( $hasReportDateAxis )
			{
				$reportTail .=
					"				<div class='report-line line-item abstract final'></div>";
			}

			$firstFact = "first-fact";
			for ( $i = 0; $i < $headerColumnCount; $i++ )
			{
				$reportTail .= "				<div class='report-line abstract-value final $firstFact' ></div>";
				$firstFact = '';
			}

			$reportTail .=
				"			</div>" .
				"			<div></div>" .
				"		</div>";

			if ( $footnotes )
			{
				$footnoteHtml = "";
				foreach ( $footnotes as $hash => $footnote )
				{
					$footnoteHtml .= "<div class='footnote-id'>{$footnote['id']}</div><div class='footnote-text'>{$footnote['text']}</div>";
				}
				$reportTail .=
					"		<div class='xbrl-footnotes'>" .
					"			<div class='footnote-header'>Footnotes</div>" .
					"			<div class='footnote-list'>" .
					"				$footnoteHtml" .
					"			</div>" .
					"		</div>";
			}

			$reportTail .=
				"	</div>" .
				"";

			return $reportTail;
		};

		$reportDateColumn = $hasReportDateAxis ? ' auto ' : '';

		// Now workout the layout.
		$createLayout = function(
				$accumulatedTables, &$footnotes, $nodes, $lineItems = false, $patternType = 'set',
				$main = false,  &$row = 0, $headersDisplayed = false, $depth = 0, $excludeEmptyHeadrers = false, $lasts = array() )
			use( &$createLayout, &$getStatedFacts, &$getSubNodeLabels, $instance, &$axes,
				 $columnCount, &$columnLayout, &$columnRefs, &$contextRefColumns, $elr,
				 &$contexts, $factsLayout, &$resultFactsLayout, $headerColumnCount, $headerRowCount, $rowCount,
				 &$factSetTypes, $hasReportDateAxis, &$tail, &$top, &$network,
				 $entityQName, $formulas, $observer, $evaluationResults, &$nodesToProcess,
				 $reportDateColumn, &$singleMemberAxes
			)
		{
			$divs = array();
			$trailingNodes = true;

			$renderHeader = function( $nodeTaxonomy, $nodeElement, $columnCount, $columnLayout, $headerRowCount, $hasReportDateAxis, $lineItems, $text, &$divs )
			{
				// This is the line-item header
				$divs[] =	"			<div class='report-header line-item' style='grid-area: 1 / 1 / span $headerRowCount / span 1;'>$text</div>";
				if ( $hasReportDateAxis )
				{
					$reportDateAxisTaxonomy = $nodeTaxonomy->getTaxonomyForXSD( $hasReportDateAxis );
					$reportDateAxisElement = $reportDateAxisTaxonomy->getElementById( $hasReportDateAxis );
					$text = $reportDateAxisTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $reportDateAxisTaxonomy->getTaxonomyXSD() . '#' . $reportDateAxisElement['id'] );
					$divs[] =	"			<div class='report-header axis-label line-item' style='grid-area: 1 / 2 / span $headerRowCount / span 1;'>$text</div>";
				}

				foreach ( $columnLayout as $row => $columns )
				{
					$column = 2 + ( $hasReportDateAxis ? 1 : 0 );
					$headerRow = $row + 1;
					$rowClass = $row % 2 ? "member-label" : "axis-label";
					if ( $headerRow == count( $columnLayout ) )
					{
						$rowClass .= " last";
					}
					foreach ( $columns as $columnSpan )
					{
						$columnClass = isset( $columnSpan['default-member'] ) && $columnSpan['default-member'] ? ' default-member' : '';
						$columnClass .= isset( $columnSpan['domain-member'] ) && $columnSpan['domain-member'] ? ' domain-member' : '';
						$columnClass .= isset( $columnSpan['root-member'] ) && $columnSpan['root-member'] ? ' root-member' : '';

						$span = $columnSpan['span'];
						$divs[] = "			<div class='report-header $rowClass$columnClass' style='grid-area: $headerRow / $column / $headerRow / span $span;'>{$columnSpan['text']}</div>";

						$column += $span;
						if ( $column > $columnCount + 1)
						{
							XBRL_Log::getInstance()->warning( "The number of generated header columns ($column) is greater than the number of expected columns ($columnCount)" );
						}
					}
				}
			};

			$firstRow = reset( $nodes );
			$lastRow = end( $nodes );
			if ( $patternType == 'rollup' ) $depth++;

			foreach ( $nodes as $label => $node )
			{
				if ( $nodesToProcess && ! in_array( $label, $nodesToProcess ) ) continue;

				$first = $node == $firstRow;
				$last = $node == $lastRow;
				$trailingNodes = true;

				$abstractLineItems = $node['modelType'] == 'cm.xsd#cm_Abstract';
				$thisLineItems = $node['modelType'] == 'cm.xsd#cm_LineItems';
				$lineItems |= $thisLineItems | $abstractLineItems;
				if ( $lineItems )
				{
					/** @var XBRL_DFR $nodeTaxonomy */
					$nodeTaxonomy = $this->getTaxonomyForXSD( $label );
					$nodeElement = $nodeTaxonomy->getElementById( $label );
					$preferredLabels = isset( $node['preferredLabel'] ) ? array( $node['preferredLabel'] ) : null;
					// Do this because $label includes the preferred label roles and the label passed cannot include it
					$text = $nodeTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $nodeTaxonomy->getTaxonomyXSD() . '#' . $nodeElement['id'], $preferredLabels );
					$title =  "{$nodeTaxonomy->getPrefix()}:{$nodeElement['name']}";

					if ( isset( $nodeElement['balance'] ) || isset( $nodeElement['periodType'] ) )
					{
						$titleSuffix = array();
						if ( isset( $nodeElement['balance'] ) ) $titleSuffix[] = $nodeElement['balance'];
						if ( isset( $nodeElement['periodType'] ) ) $titleSuffix[] = $nodeElement['periodType'];
						$title .= " (" . implode( " - ", $titleSuffix ) . ")";
					}

					// This is where the headers are laid out
					if ( ! $headersDisplayed )
					{
						$renderHeader( $nodeTaxonomy, $nodeElement, $columnCount, $columnLayout, $headerRowCount, $hasReportDateAxis, $lineItems, $text, $divs );
						$headersDisplayed = true;
					}

					if ( $thisLineItems )
					{
					}
					else if ( $node['modelType'] == 'cm.xsd#cm_Table' || $node['modelType'] == 'cm.xsd#cm_Axis' )
					{
					}
					else if ( $node['modelType'] == 'cm.xsd#cm_Abstract' )
					{
						if ( $excludeEmptyHeadrers )
						{
							$subNodeLabels = $getSubNodeLabels( $node['children'] );
							// Create a set of fact layout elements for the sub-nodes
							$subNodesFactsLayout = array_filter( array_intersect_key( $factsLayout, array_flip( $subNodeLabels ) ), function( $node )
							{
								return count( $node['columns'] );
							} );

							if ( ! $subNodesFactsLayout )
							{
								continue;
							}
						}

						$row++;
						$main = false;
						if ( isset( $node['patterntype'] ) )
						{
							if ( $node['patterntype'] == 'rollup' )
							{
								$main = $patternType != $node['patterntype'];
							}
							$patternType = $node['patterntype'];
						}
						// Abstract rows laid out here
						$startDateAxisStyle = $hasReportDateAxis ? 'style="grid-column-start: span 2;"' : '';
						$divs[] = "		<div class='report-line line-item abstract depth$depth' data-row='$row' $startDateAxisStyle title='$title'>$text</div>";
						$firstFact = "first-fact";
						for ( $i = 0; $i < $headerColumnCount; $i++ )
						{
							$divs[] = "<div class='report-line abstract-value $firstFact' data-row='$row'></div>";
							$firstFact = '';
						}
					}
					else
					{
						// All other (concept) rows laid out here
						$row++;
						$totalClass = isset( $node['total'] ) && $node['total'] ? 'total' : '';
						$totalClass .= $main && $totalClass ? ' main' : '';

						$rowDetails = isset( $factsLayout[ $label ] ) ? $factsLayout[ $label ] : array();
						if ( $rowDetails )
						{
							$columnFacts = isset( $rowDetails['columns'] ) ? $rowDetails['columns'] : array();

							// This line MUST appear after preferred labels have been processed
							$divs[] = "		<div class='report-line line-item $totalClass depth$depth' data-row='$row' title='$title'>$text</div>";

							$columns = array();
							// Look for the fact with $contextRef
							if ( $hasReportDateAxis )
							{
								$axis = $axes[ $hasReportDateAxis ];
								// Find the segment with $hasReportDateAxis
								if ( isset( $node['preferredLabel'] ) && $last ) // && $node['preferredLabel'] == XBRL_Constants::$labelRoleRestatedLabel )
								{
									$totalClass = 'total';
								}

								$reportAxisMemberClass = '';
								$fact = reset( $columnFacts );
								$text = ''; // By default there is no text for the column
								$occ = isset( $contexts[ $fact['contextRef'] ]['entity']['segment'] )
									? $contexts[ $fact['contextRef'] ]['entity']['segment']
									: ( isset( $contexts[ $fact['contextRef'] ]['entity']['scenario'] )
										? $contexts[ $fact['contextRef'] ]['entity']['scenario']
										: ( isset( $contexts[ $fact['contextRef'] ]['segment'] )
											? $contexts[ $fact['contextRef'] ]['segment']
											: ( isset( $contexts[ $fact['contextRef'] ]['scenario'] )
												? $contexts[ $fact['contextRef'] ]['scenario']
												: null ) ) );

								if ( $occ && isset( $occ['explicitMember'] ) )
								{
									$reportDateAxisTaxonomy = $nodeTaxonomy->getTaxonomyForXSD( $hasReportDateAxis );
									$reportDateAxisElement = $reportDateAxisTaxonomy->getElementById( $hasReportDateAxis );
									$qname = $reportDateAxisTaxonomy->getPrefix() . ":" . $reportDateAxisElement['name'];

									$explicitMembers = $occ['explicitMember'];
									$em = @reset( array_filter( $explicitMembers, function( $em ) use( $qname ) {
										return $em['dimension'] == $qname;
									} ) );

									if ( $em && isset( $em['member'] ) )
									{
										$member = $em['member'];
										$qname = qname( $member, $instance->getInstanceNamespaces() );
										$memberTaxonomy = $nodeTaxonomy->getTaxonomyForNamespace( $qname->namespaceURI );
										if ( $memberTaxonomy )
										{
											$memberElement = $memberTaxonomy->getElementByName( $qname->localName );
											{
												if ( $memberElement )
												{
													$text = $nodeTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $memberTaxonomy->getTaxonomyXSD() . '#' . $memberElement['id'] );
												}
											}
										}
									}
								}
								else if ( $axis['default-member'] )
								{
									$text = $nodeTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $axis['default-member'] );
									$reportAxisMemberClass = 'default-member';
								}

								$divs[] = "<div class='report-line member-label value $reportAxisMemberClass' title='$title'>$text</div>";
							}
							else if ( isset( $node['preferredLabel'] ) && $node['preferredLabel'] == XBRL_Constants::$labelRolePeriodEndLabel && $patternType == 'rollforward' )
							{
								$totalClass = 'total';
							}

							// The last row of the column layout is a list of columns and ids
							$lastRowLayout = end( $columnLayout );

							foreach ( $columnFacts as $columnIndex => $fact )
							{
								if ( isset( $fact['contextRefRestated'] ) && $fact['contextRefRestated'] )
								{
									$fact['contextRef'] = $fact['contextRefRestated'];
								}

								$footnoteIds = array();

								$fn = $instance->getFootnoteForFact( $fact );
								if ( $fn )
								{
									foreach ( $fn as $index => $footnote )
									{
										if ( isset( $footnotes[ md5( $footnote ) ] ) )
										{
											$footnoteIds[] = $footnotes[ md5( $footnote ) ]['id'];
											continue;
										}

										$footnotes[ md5( $footnote ) ] = array( 'text' => $footnote );
										$footnotes[ md5( $footnote ) ]['id'] = count( $footnotes );
										$footnoteIds[] = count( $footnotes );
									}
								}

								// Check that the column is still reportable.  It might have been removed as empty
								if ( ! isset( $lastRowLayout[ $columnIndex ] ) ) continue;
								$factIsNumeric = is_numeric( $fact['value'] );
								$currentColumn = $lastRowLayout[ $columnIndex ];
								$type = (string) XBRL_Instance::getElementType( $fact );
								$valueClass = empty( $type ) ? '' : $nodeTaxonomy->valueAlignment( $type, $instance );
								$columnTotalClass = ( $currentColumn['default-member'] || $currentColumn['domain-member'] /** || $currentColumn['root-member'] */ ) && $valueClass != 'left'
									? 'total'
									: '';
								if ( $columnIndex === 0 ) $valueClass .= ' first-fact';

								if ( isset( $node['preferredLabel'] ) && $node['preferredLabel'] == XBRL_Constants::$labelRoleNegatedLabel && $factIsNumeric )
								{
									$fact['value'] *= -1;
								}

								if ( isset( $fact['decimals'] ) && $fact['decimals'] < 0 ) $fact['decimals'] = 0;
								$value = $nodeTaxonomy->formattedValue( $fact, $instance, false );
								if ( strlen( $fact['value'] ) && is_numeric( $fact['value'] ) )
								{
									if ( $this->negativeStyle == NEGATIVE_AS_BRACKETS )
									{
										if ( $fact['value'] < 0 )
										{
											$valueClass .= ' neg';
											// $value = "(" . abs( $fact['value'] ) . ")";
											$fact['value'] = abs( $fact['value'] );
											$value = "(" . $nodeTaxonomy->formattedValue( $fact, $instance, false ) . ")";
											$fact['value'] *= -1;
										}
										else $valueClass .= ' pos';
									}
								}

								$footnoteClass = "";
								$footnoteDiv = "<div></div>";
								if ( $footnoteIds )
								{
									$footnoteClass = 'xbrl-footnote';
									$footnoteDiv = "<div class='footnote-id'>" . implode( ',', $footnoteIds ) . "</div>";
								}

								$valueDiv = "<div class='value'>$value</div>";

								$title  = '';
								$statusDiv = '<div></div>';
								$statusClass = '';
								// if ( isset( $factsLayout[ $label ]['rollupTotals'] ) )
								if ( isset( $rowDetails['rollupTotals'] ) )
								{
									$totalValue = $instance->getNumericPresentation( $fact );
									// $rollupTotal = isset( $factsLayout[ $label ]['rollupTotals'][ $columnIndex ] ) ? $factsLayout[ $label ]['rollupTotals'][ $columnIndex ] : 0;
									// if ( isset( $factsLayout[ $label ]['calcTotals'][ $columnIndex ] ) )
									if ( isset( $rowDetails['calcTotals'][ $columnIndex ] ) )
									{
										$calcTotal = $rowDetails['calcTotals'][ $columnIndex ];
										if ( /* $rollupTotal == $totalValue && */ $calcTotal == $totalValue )
										{
											$statusClass = 'match';
											$title = 'The rollup total matches the sum of the report component values';
										}
										else
										{
											$statusClass = "mismatch";
											// $title = "The rollup total does not match the sum of the report components ($rollupTotal) or the sum of the calculation components ($calcTotal)";
											$title = "The rollup total does not match the sum of the calculation components ($calcTotal)";
										}

										$statusDiv = "<div class='$statusClass'></div>";
										$title = "title='$title'";
									}
								}

								$columns[ $columnIndex ] = "<div class='report-line value $totalClass $columnTotalClass $valueClass $statusClass $footnoteClass' $title data-row='$row'>$footnoteDiv$statusDiv$valueDiv</div>";
							}
							unset( $fact ); // Gets confusing having old values hanging around
							// unset( $facts );
							unset( $columnFacts );

							// Fill in
							foreach ( $lastRowLayout as $columnIndex => $column )
							{
								if ( isset( $columns[ $columnIndex ] ) ) continue;
								$firstFact = $columnIndex === 0 ? 'first-fact' : '';
								$columns[ $columnIndex ] = "<div class='report-line value $totalClass $firstFact' data-row='$row'></div>";
							}

							ksort( $columns );
							$divs = array_merge( $divs, $columns );

							if ( $totalClass )
							{
								for ( $c = 0; $c < $columnCount - count( $columns); $c++ )
								{
									$divs[] = "<div class='report-line line-item after-total'></div>";
								}
								$firstFact = "first-fact";
								for ( $c = 0; $c < count( $columns ); $c++ )
								{
									$divs[] = "<div class='report-line value after-total $firstFact'></div>";
									$firstFact = '';
								}
							}
							unset( $columns );
						}
					}
				}

				if ( ! isset( $node['children'] ) || ! $node['children'] ) continue;

				// May need to present a sub table
				if ( isset( $network['tables'][ $label ] ) && ! isset( $accumulatedTables[ $label ] ) )
				{
					// Nested report

					// Create the next report section
					$nextAccumulatedTables = $accumulatedTables;
					$nextAccumulatedTables[ $label ] = $network['tables'][ $label ];

					$resultFactsLayout[ $label ] = array();
					$render = $this->renderReportTable( $network, $node['children'], $elr, $instance, $entityQName, $formulas, $observer, $evaluationResults, $resultFactsLayout, $nextAccumulatedTables, $nodesToProcess, true, $excludeEmptyHeadrers, $row, array_merge( $lasts, array( $last ) ), $text );

					if ( ! $render ) continue;

					// Close out the earlier section
					$divs[] = $tail( $footnotes, $hasReportDateAxis, $headerColumnCount );
					$divs[] = $render;

					// Only report a sub-table if there is something worth reporting.
					// If its the last row of the last sub-report or the report did not produce ab output
					if ( count( $lasts ) == count( array_filter( $lasts ) ) )
					{
						$trailingNodes = false;
						continue;
					}

					// Create slicers and opening <div> elements for the rest of the previous section
					$divs[] = $this->renderSlicers( $network, $instance, $entityQName, $singleMemberAxes, null, new ContextsFilter( $instance, $contexts ) );
					$columnWidth = $headerColumnCount == 1 || array_search( 'text', $factSetTypes ) ? 'minmax(100px, max-content)' : '100px';
					$divs[] = $top( $reportDateColumn, $headerColumnCount, $columnWidth );
					unset( $nextAccumulatedTables );

					// Make sure the headings are repeated
					$headersDisplayed = false;
				}
				else
				{
					$result = $createLayout( $accumulatedTables, $footnotes, $node['children'], $lineItems, $patternType, $main, $row, $headersDisplayed, $depth, $excludeEmptyHeadrers, array_merge( $lasts, array( $last ) ) );
					$divs = array_merge( $divs, $result['divs'] );
					$headersDisplayed = $result['headersDisplayed'];
					$trailingNodes = $result['trailingNodes'];
				}
			}

			return array( 'divs' => $divs, 'trailingNodes' => $trailingNodes, 'headersDisplayed' => $headersDisplayed );
		};

		$columnWidth = $headerColumnCount == 1 || array_search( 'text', $factSetTypes ) ? 'minmax(100px, max-content)' : '100px';

		$footnotes = array();

		$layout = $createLayout( $accumulatedTables, $footnotes, $nodes, $lineItems );
		$reportTable = $this->renderSlicers( $network, $instance, $entityQName, $singleMemberAxes, null, new ContextsFilter( $instance, $contexts ) ) .
						$top( $reportDateColumn, $headerColumnCount, $columnWidth ) .
						implode( '', $layout['divs'] );

		// When there are no trailing nodes, no header will be added so no $tail is required.
		if ( $layout['trailingNodes'] )
		{
			$reportTable .= $tail( $footnotes, $hasReportDateAxis, $headerColumnCount );
		}

		return $reportTable;
	}

	/**
	 * Render a report with columns for any years and dimensions
	 * @param array $network			An array generated by the validsateDLR process
	 * @param string $elr				The extended link role URI
	 * @param XBRL_Instance $instance	The instance being reported
	 * @param QName $entityQName
	 * @param XBRL_Formulas $formulas	The evaluated formulas
	 * @param Observer $observer		An obsever with any validation errors
	 * @param $evaluationResults		The results of validating the formulas
	 * @param $echo						If true the HTML will be echoed
	 * @return string
	 */
	private function renderNetworkReport( $network, $elr, $instance, $entityQName, $formulas, $observer, $evaluationResults, $echo = true )
	{
		$componentTable = $this->renderComponentTable( $network, $elr );

		$structureTable = $this->renderModelStructure( $network );

		$slicers = ""; // $this->renderSlicers( $network, $instance, $entityQName );

		// Filter the contexts by axes
		// All the contexts without
		$accumulatedTables = isset( $network['tables'][null]) ? array( $network['tables'][null] ) : array();

		if ( count( $network['hierarchy'] ) == 1 && isset( $network['tables'][ key( $network['hierarchy'] ) ] ) )
		{
			$accumulatedTables[ key( $network['hierarchy'] ) ] = $network['tables'][ key( $network['hierarchy'] ) ];
		}

		$nodesToProcess = null;

		$factsLayouts[] = array();
		$excludeEmptyHeadrers = false;
		$row = 0;
		$reportTable = $this->renderReportTable(
			$network, $network['hierarchy'], $elr, $instance, $entityQName, $formulas,
			$observer, $evaluationResults, $factsLayouts, $accumulatedTables, $nodesToProcess,
			false, $excludeEmptyHeadrers, $row, array() );

		$factsLayouts = array_filter( $factsLayouts );

		if ( ! $reportTable )
		{
			$reportTable =
				"	<div style='display: grid; grid-template-columns: 1fr; '>" .
				"		<div style='display: grid; grid-template-columns: auto 1fr;'>" .
				"			There is no data to report " .
				"		</div>" .
				"	</div>";
		}

		$renderFactsTable = $this->renderFactsTable( $network, $elr, $instance, $entityQName, $factsLayouts );

		$businessRules = $this->renderBusinessRules( $network, $elr, $instance, $entityQName, $factsLayouts );

		echo "$elr\n";

		$report =
			"<div class='report-selection'>
				<span class='report-selection-title'>Report sections:</span>
				<input type='checkbox' name='report-selection' id='report-selection-structure' data-class='structure-table' />
				<label for='report-selection-structure'>Structure</label>
				<input type='checkbox' name='report-selection' id='report-selection-slicers' data-class='slicers-table' checked />
				<label for='report-selection-slicers'>Slicers</label>
				<input type='checkbox' name='report-selection' id='report-selection-report' data-class='report-section' checked />
				<label for='report-selection-report'>Report</label>
				<input type='checkbox' name='report-selection' id='report-selection-facts' data-class='facts-section'  />
				<label for='report-selection-facts'>Facts</label>
				<input type='checkbox' name='report-selection' id='report-selection-Rules' data-class='business-rules-section' />
				<label for='report-selection-rules'>Rules</label>
				</div>" .
			"<div class='model-structure'>" .
			$componentTable . $structureTable . $slicers . $reportTable . $renderFactsTable . $businessRules .
			"</div>";

		if ( $echo )
		{
			// file_put_contents("report.xml", $report );
			echo $report;
		}

		return $report;
	}

	/**
	 * Render a report with columns for any years and dimensions
	 * @param array $network			An array generated by the validsateDLR process
	 * @param string $elr				The extended link role URI
	 * @param XBRL_Instance $instance	The instance being reported
	 * @param QName $entityQName
	 * @param array $factsLayout
	 * @return string
	 */
	private function renderBusinessRules( $network, $elr, $instance, $entityQName, $reportFactsLayout )
	{
		if ( ! isset( $this->calculationNetworks[ $elr]['calculations'] ) )
		{
			return "<div class='business-rules-section hide-section'>There are no business rules</div>";
		}

		$reportTable =
			"	<div class='business-rules-section hide-section' style='display: grid; grid-template-columns: auto 1fr; '>" .
			"		<div>Business Rules</div><div></div>";

		// Report each total
		foreach ( $this->calculationNetworks[ $elr]['calculations'] as $calcTotalLabel => $calculations )
		{
			$calcTotalText = $this->getTaxonomyDescriptionForIdWithDefaults( $calcTotalLabel );
			$calcTaxonomy = $this->getTaxonomyForXSD( $calcTotalLabel );
			$calcElement = $calcTaxonomy->getElementById( $calcTotalLabel );
			$header = "$calcTotalText ({$calcTaxonomy->getPrefix()}:{$calcElement['name']})";

			// Find the $factsLayout containing $calcTotalLabel
			$totalFactsLayout = array_filter( $reportFactsLayout, function( $factsLayout ) use( $calcTotalLabel )
			{
				return isset( $factsLayout['data'][ $calcTotalLabel ] );
			} );
			if ( ! $totalFactsLayout ) continue;
			$totalFactsLayout = array_map( function( $factsLayout ) { return $factsLayout['data']; }, $totalFactsLayout );
			// if ( ! isset( $factsLayout[ $calcTotalLabel ] ) ) continue;

			foreach ( $totalFactsLayout as $reportLabel => $factsLayout )
			{
				$columnCount = max( array_map( function( $row ) { return count( $row['columns'] ); }, $factsLayout ) );

				// And each period
				for ( $columnIndex = 0; $columnIndex < $columnCount; $columnIndex++ )
				{
					$totalRow = $factsLayout[ $calcTotalLabel ];
					if ( ! isset( $totalRow['calcTotals'][ $columnIndex ] ) ) continue;

					$contextRefs = array_unique( array_filter( array_values( array_map( function( $row ) use( $columnIndex )
					{
						return isset( $row['columns'][ $columnIndex ]['contextRef'] )
							? $row['columns'][ $columnIndex ]['contextRef']
							: false;
					}, $factsLayout ) ) ) );

					if ( ! $contextRefs ) continue;

					$contextsFilter = $instance-> getContexts()->getContextsByRef( $contextRefs );
					if ( ! $contextsFilter->count() )
					{
						// This should never happen
						echo "Oops.  No valid contexts found for context refs: " . implode( ",", $contextRefs ) . "\n";
						continue;
					}

					$reportTable .= $this->renderSlicers( $network, $instance, $entityQName, null, 'business-rules-slicers-table', $contextsFilter ) . "<div></div>";

					$reportTable .=
						"		<div class='business-rules-table' style='display: grid; grid-template-columns: 1fr;'>" .
						"			<div class='business-rules-roles'>$header</div>" .
						"			<div class='business-rules-rows' style='display: grid; grid-template-columns: 400px  repeat( 4, auto );' >" .
						"				<div class='business-rules-header line-item'>Line item</div>" .
						"				<div class='business-rules-header calculated'>Calculated</div>" .
						"				<div class='business-rules-header sign'></div>" .
						"				<div class='business-rules-header balance'>Balance</div>" .
						"				<div class='business-rules-header decimals last'>Decimals</div>";

					foreach ( $calculations as $calcLabel => $calcItem )
					{
						$calcTaxonomy = $this->getTaxonomyForXSD( $calcLabel );
						$calcElement = $calcTaxonomy->getElementById( $calcLabel );
						$calcQName = "{$calcTaxonomy->getPrefix()}:{$calcElement['name']}";

						$text = $calcTaxonomy->getTaxonomyDescriptionForIdWithDefaults( $calcLabel );
						$row = isset( $factsLayout[ $calcLabel ] ) ? $factsLayout[ $calcLabel ] : null;
						$value = $row ? $instance->getNumericPresentation($row['columns'][ $columnIndex ]) : '';
						$sign = isset( $calcItem['weight'] ) && $calcItem['weight'] < 0 ? '-' : '+';

						$valueClass = "";
						if ( $this->negativeStyle == NEGATIVE_AS_BRACKETS )
						{
							if ( $value < 0 )
							{
								$valueClass = ' neg';
								$value = "(" . abs( $value ) . ")";
							}
							else $valueClass = ' pos';
						}

						$reportTable .=
							"				<div class='business-rules-row line-item' title='$calcQName'>$text</div>" .
							"				<div class='business-rules-row calculated $valueClass'>$value</div>" .
							"				<div class='business-rules-row sign'>$sign</div>" .
							"				<div class='business-rules-row balance'>{$calcElement['balance']}</div>" .
							"				<div class='business-rules-row decimals last'>{$row['columns'][ $columnIndex ]['decimals']}</div>";
					}

					$calcTaxonomy = $this->getTaxonomyForXSD( $calcTotalLabel );
					$calcElement = $calcTaxonomy->getElementById( $calcTotalLabel );
					$calcQName = "{$calcTaxonomy->getPrefix()}:{$calcElement['name']}";

					$totalValue = $instance->getNumericPresentation($totalRow['columns'][ $columnIndex ]);
					$matchClass = $totalValue == $totalRow['calcTotals'][ $columnIndex ] ? 'match' : 'mismatch';

					$valueClass = "";
					if ( $this->negativeStyle == NEGATIVE_AS_BRACKETS )
					{
						if ( $totalValue < 0 )
						{
							$valueClass .= ' neg';
							$totalValue = "(" . abs( $totalValue ) . ")";
						}
						else $valueClass .= ' pos';
					}

					$reportTable .=
						"				<div class='business-rules-row line-item' title='$calcQName'>$calcTotalText</div>" .
						"				<div class='business-rules-row calculated total $valueClass $matchClass'>$totalValue</div>" .
						"				<div class='business-rules-row sign'></div>" .
						"				<div class='business-rules-row balance'>{$calcElement['balance']}</div>" .
						"				<div class='business-rules-row decimals last'>{$totalRow['columns'][ $columnIndex ]['decimals']}</div>";

					$reportTable .=
						"				<div class='business-rules-row line-item final'></div>" .
						"				<div class='business-rules-row calculated final'></div>" .
						"				<div class='business-rules-row sign final'></div>" .
						"				<div class='business-rules-row balance final'></div>" .
						"				<div class='business-rules-row decimals final last'></div>";

					$reportTable .=
						"			</div>" .
						"		</div>" .
						"		<div></div>";
				}
			}
		}

		$reportTable .=
			"	</div>" .
			"";

		return $reportTable;
	}

}
