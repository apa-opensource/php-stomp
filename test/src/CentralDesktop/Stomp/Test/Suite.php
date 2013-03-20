<?php
/**
 *
 * Copyright 2005-2006 The Apache Software Foundation
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace CentralDesktop\Stomp\Test;

use CentralDesktop\Stomp;


/**
 * Static test suite.
 *
 * @package Stomp
 * @author  Michael Caplan <mcaplan@labnet.net>
 * @version $Revision: 23 $
 */
class Suite extends PHPUnit_Framework_TestSuite {
    /**
     * Constructs the test suite handler.
     */
    public
    function __construct() {
        $this->setName('StompSuite');
        $this->addTestSuite('FailoverTest');
        $this->addTestSuite('Test');
        $this->addTestSuite('SslTest');
    }

    /**
     * Creates the suite.
     */
    public static
    function suite() {
        return new self();
    }
}
