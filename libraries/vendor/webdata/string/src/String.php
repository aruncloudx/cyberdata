<?php
/**
 * Part of the Webdata Framework String Package
 *
 * @copyright  Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Webdata\String;

/**
 * String handling class for utf-8 data
 * Wraps the phputf8 library
 * All functions assume the validity of utf-8 strings.
 *
 * @since       1.0
 * @deprecated  2.0  Use StringHelper instead
 */
abstract class String extends StringHelper
{
}
