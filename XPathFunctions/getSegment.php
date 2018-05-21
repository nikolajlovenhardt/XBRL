<?php

/**
 * XPath 2.0 for PHP
 *  _					   _	 _ _ _
 * | |   _   _  __ _ _   _(_) __| (_) |_ _   _
 * | |  | | | |/ _` | | | | |/ _` | | __| | | |
 * | |__| |_| | (_| | |_| | | (_| | | |_| |_| |
 * |_____\__, |\__, |\__,_|_|\__,_|_|\__|\__, |
 *	     |___/	  |_|					 |___/
 *
 * @author Bill Seddon
 * @version 0.9
 * @Copyright ( C ) 2017 Lyquidity Solutions Limited
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * ( at your option ) any later version.
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

namespace XBRL\functions;

use lyquidity\xml\xpath\XPathNavigator;
use lyquidity\xml\xpath\XPathNodeType;
use lyquidity\XPath2\NodeProvider;
use lyquidity\XPath2\Properties\Resources;
use lyquidity\XPath2\XPath2Context;
use lyquidity\XPath2\XPath2NodeIterator;
use lyquidity\XPath2\Iterator\DocumentOrderNodeIterator;
use lyquidity\XPath2\XPath2Exception;

// Make sure any required functions are imported
require_once "getEntity.php";

/**
 * Returns the identifier element associated with the given item.
 * @param XPath2Context $context
 * @param NodeProvider $provider
 * @param array $args
 * @return XPath2NodeIterator	Returns the identifier element associated with the given item.
 *
 * This function has one real parameter:
 *
 * identifier-scheme	element(xbrli:identifier)	The identifier that the scheme is required for.
 */
function getSegment( $context, $provider, $args )
{
	if ( count( $args ) != 1 )
	{
		throw XPath2Exception::withErrorCodeAndParams( "XPST0017", Resources::XPST0017,
			array(
				"concat",
				count( $args ),
				\XBRL_Constants::$standardPrefixes[ STANDARD_PREFIX_FUNCTION_INSTANCE ],
			)
		);
	}

	/**
	 * @var XPath2NodeIterator $arg
	 */
	$arg = $args[0];
	if ( is_null( $arg ) ) return $args;

	try
	{
		/**
		 * @var XPathNavigator $xbrlContext
		 */
		$xbrlEntity = getEntity( $context, $provider, $args );

		// This should be an element System.Xml.XPathNavigator descendent
		if ( ! $xbrlEntity instanceof XPathNavigator || $xbrlEntity->getLocalName() != "entity" )
		{
			throw new \InvalidArgumentException();
		}

		// There can be more than one element
		$segments = array();

		$xbrlEntity->MoveToChild( XPathNodeType::Element );
		while ( true )
		{
			while ( $xbrlEntity->getLocalName() != "segment" )
			{
				if ( ! $xbrlEntity->MoveToNext( XPathNodeType::Element ) )
				{
					break 2;
				}
			}

			$segments[] = $xbrlEntity->CloneInstance();

			if ( ! $xbrlEntity->MoveToNext( XPathNodeType::Element ) )
			{
				break;
			}
		}

		return DocumentOrderNodeIterator::fromItemset( $segments );
		// return $xbrlEntity;
	}
	catch ( XPath2Exception $ex)
	{
		// Do nothing
	}
	catch ( \Exception $ex)
	{
		// Do nothing
	}

	throw XPath2Exception::withErrorCode( "XPTY0004", Resources::GeneralXFIFailure );
}
