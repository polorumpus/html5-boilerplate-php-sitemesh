<? 
/* Sitemesh.PHP
 * By David Fox <dfox@crunchbc.com>
 * Copyright (c) 2008, Crunch Brand Communications, Inc. (http://crunchbc.com)
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you
 * may not use this file except in compliance with the License.  You
 * may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
 * implied.  See the License for the specific language governing
 * permissions and limitations under the License.
 */
?>
<?php



define('SITEMESH_DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT']);


define('SITEMESH_CONFIG_PATH', SITEMESH_DOCUMENT_ROOT . '/config/sitemesh.xml');


define('SITEMESH_PAGE_PARSE_DEBUG', true);


define('SITEMESH_CONFIG_PARSE_DEBUG', true);


define('SITEMESH_DEFAULT_PAGE_PARSER', 'SiteMeshDefaultPageParser');


define('SITEMESH_DEFAULT_PAGE_CLASS', 'SiteMeshDefaultPage');


define('SITEMESH_ENCODING', 'ISO-8859-1');


interface SiteMeshMapper{
  
    
    function map(&$params);
}


interface SiteMeshPage{

     
    function getTitle();

    
    function &getContent();
    
    
    function getProperty($name);
}


interface SiteMeshPageParser{

    
    function  &parse(&$content);
}


class SiteMeshRequestParamMapper implements SiteMeshMapper{

    
    public function map(&$params){
	if (isset($params['param'])){
	    $param = $params['param'];
	    if (isset($_REQUEST[$param])){
		return $_REQUEST[$param];
	    }
	}
	return null;
    }
}



class SiteMeshPathMapper implements SiteMeshMapper{
  
    
    public function map(&$params){
	foreach ($params as $pattern=>$value){
	    if (ereg($pattern, $_SERVER['PHP_SELF'])){
		return $value;
	    }
	}
	return null;
    }
}


class SiteMeshStaticMapper implements SiteMeshMapper{
  
    
    public function map(&$params){
	if (isset($params['value'])){
	    return $params['value'];
	}
	return null;
    }
}


class SiteMeshConfigFactory{

    private $state;
    private $decorators;
    private $parsers;
    private $mappers;
    private $exclude;
    private $parser;
    private $decorator;
    private $decoratorParams;
    private $currentDecorator;
    private $currentMapping;
    private $currentMapper;
    private $currentParams;
    private $isMapped;
    private $decoratorBasePath;
    private $debug = false;
    
    private function handleError($parser, $message){
	if ($this->debug){
	    throw new Exception('Sitemesh configuration error: Line: ' . 
				xml_get_current_line_number($parser) . ', Column: ' .
				xml_get_current_column_number($parser) . ': ' . 
				$message);
	}
    }
    
    private function handleStartElement($parser, $data, $attr){
	switch ($data){
	case 'sitemesh':
	    if ($this->state !== 'default'){
		return $this->handleError($parser, 'Invalid config section: <sitemesh>: ' . 
				   'Sitemesh must be the root element');
	    }
	    $this->state = 'sitemesh';
	    break;
	case 'parsers':
	    $this->state = $data;
	    break;
	case 'decorators':
	    $dir = null;
	    if (isset($attr['directory'])){
		$dir = $attr['directory'];
		$len = strlen($dir);
		if ($len > 0 && $dir[$len - 1] !== '/'){
		    $dir .= '/';
		}
	    }
	    $this->decoratorBasePath = SITEMESH_DOCUMENT_ROOT . $dir;
	    $this->state = $data;
	    break;
	case 'mappers':
	    if (count($this->decorators) === 0){
		return $this->handleError($parser, 'Invalid configuration: Missing decorators section');
	    }
	    if ($this->state !== 'sitemesh'){
		return $this->handleError($parser, 'Invalid config section: <mappers>: ' . 
				   'Expecting </' . $this->state . '>');
	    }
	    $this->state = 'mappers';
	    break;
	case 'parser':
	    if ($this->state !== 'parsers'){
		return $this->handleError($parser, 'Invalid config section: <parser>: Expecting <parsers>');
	    }
	    $this->state = 'parser';
	    $this->parsers[$attr['name']] = $attr['type'];
	    break;
	case 'decorator':
	    if ($this->state != 'decorators'){
		return $this->handleError($parser, 'Invalid config section: <decorator>: Expecting <decorators>');
	    }   
	    if (!isset($attr['name'])){
		return $this->handleError($parser, 'Invalid decorator element: "name" attribute is required');
	    }
	    if (!isset($attr['page'])){
		return $this->handleError($parser, 'Invalid decorator element: "page" attribute is required');
	    }
	    $this->currentDecorator = $attr['name'];
	    $this->decorators[$this->currentDecorator] = array($attr['page'], null);
	    $this->state = 'decorator';
	    break;
	case 'chain':
	    if ($this->state != 'mappers'){
		return $this->handleError($parser, 'Invalid config section: <chain>: Expecting <mappers>');
	    }  
	    if (!isset($attr['maps'])){
		return $this->handleError($parser, 'Invalid chain element: "maps" attribute is required');
	    }
	    $this->currentMapping = $attr['maps'];
	    $this->isMapped = false;
	    switch($this->currentMapping){
	    case 'excludes':
	    case 'parsers':
	    case 'decorators':
		break;
	    default:
		return $this->handleError($parser, 'Invalid chain "maps" attribute: "' . $this->currentMapping . 
				   '": Expecting "excludes", "parsers", or "decorators"');
	    }
	    $this->state = 'chain';
	    break;
	case 'mapper':
	    if ($this->state != 'chain'){
		return $this->handleError($parser, 'Invalid config section: <mapper>: Expecting <chain>');
	    }  
	    $this->state = 'mapper';
	    if ($this->isMapped){
		break;
	    }
	    if (!isset($attr['type'])){
		return $this->handleError($parser, 'Invalid <mapper> element: "type" attribute is required');
	    }
	    $this->currentMapper = $attr['type'];
	    if (!isset($this->mappers[$this->currentMapper])){
		$this->mappers[$this->currentMapper] = new $this->currentMapper();
	    }
	    break;
	case 'param':
	    if (!isset($attr['name'])){
		return $this->handleError($parser, 'Invalid <param> element: "name" ' .
				   'attribute is required');
	    }
	    if (!isset($attr['value'])){
		return $this->handleError($parser, 'Invalid <param> element: "value" ' .
				   'attribute is required');
	    }
	    switch($this->state){
	    case 'mapper':
		$this->currentParams[$attr['name']] = $attr['value'];
		$this->state = 'mapperParam';
		break;
	    case 'decorator':
		$this->currentParams[$attr['name']] = $attr['value'];
		$this->state = 'decoratorParam';
		break;
	    case 'chain':
		return $this->handleError($parser, 'Invalid config section: <param>: ' .
				   'Expecting <mapper>');
	    default:
		return $this->handleError($parser, 'Invalid config section: <param>: ' .
				   'Expecting <decorator> or <mapper>');
	    }
	    break;
	default:
	    return $this->handleError($parser, 'Invalid config section: <' . $data . '>');
	}
    }
    
    private function handleEndElement($parser, $data){
	

	switch($data){
	case 'sitemesh':
	    $this->state = 'default';
	    break;
	case 'parsers':
	    $this->state = 'sitemesh';
	    break;
	case 'parser':
	    $this->state = 'parsers';
	    break;
	case 'decorators':
	    $this->state = 'sitemesh';
	    break;
	case 'decorator':
	    $this->decorators[$this->currentDecorator][1] = $this->currentParams;
	    $this->currentDecorator = null;
	    $this->currentParams = array();
	    $this->state = 'decorators';
	    break;
	case 'mappers':
	    $this->state = 'sitemesh';
	    break;
	case 'chain':
	    $this->currentMapping = null;
	    $this->state = 'mappers';
	    break;
	case 'param':
	    switch ($this->state){
	    case 'mapperParam':
		$this->state = 'mapper';
		break;
	    case 'decoratorParam':
		$this->state = 'decorator';
	    }
	    break;
	case 'mapper': 
	    $this->state = 'chain';
	    if ($this->isMapped || !isset($this->mappers[$this->currentMapper])){
		break;
	    }  
	    $mapper = &$this->mappers[$this->currentMapper];
	    $result = $mapper->map($this->currentParams);
	    if ($result !== null){
		switch($this->currentMapping){
		case 'excludes':
		    $this->exclude = (($result === 'true') ? true : false);
		    break;
		case 'parsers':
		    $this->parser = $result;
		    break;
		case 'decorators':
		    if (isset($this->decorators[$result])){
			$this->decorator = $this->decoratorBasePath . $this->decorators[$result][0];
			$this->decoratorParams = $this->decorators[$result][1];
		    }
		}
		$this->isMapped = true;
	    }     
	    $this->currentParams = array();
	    $this->currentMapper = null;
	}
    }

    
    public function parse($configPath){
	
	$this->state = 'default';
	$this->exclude = false;
	$this->parser = null;
	$this->decorator = null;
	$this->currentParams = array();
	$this->currentMapping = null;
	$this->currentMapper = null;
	$this->currentDecorator = null;
	$this->isMapped = false;
	
	
	$parser = xml_parser_create();
	xml_set_object($parser, $this);
	xml_set_element_handler($parser, 'handleStartElement', 'handleEndElement');
	xml_parser_set_option ($parser, XML_OPTION_CASE_FOLDING, false);
	xml_parser_set_option ($parser, XML_OPTION_SKIP_WHITE, true);
	
	
	$handle = @fopen($configPath, "r");
	if ($handle === false) {
	    if ($this->debug){
		throw new Exception('SiteMesh config file not found: ' . $configPath);
	    }
	    return;
	}

	if (!feof($handle)) {
	    while (true){
		$buffer = fgets($handle, 4096);
		if (feof($handle)){
		    if (!xml_parse($parser, $buffer, true)){
			return $this->handleError($parser, 'Invalid XML');
		    }
		    break;
		}
		else if(!xml_parse($parser, $buffer, false)){
		    return $this->handleError($parser, 'Invalid XML');
		}
	    }
	}
	fclose($handle);
	xml_parser_free($parser);
    }
    
    
    public function getExclude(){
	return ($this->exclude || $this->decorator === null);
    }

    public function setDebug($debug){
	$this->debug = $debug;
    }
    
    
    public function getParser(){
	if (isset($this->parsers[$this->parser])){
	    $parser = $this->parsers[$this->parser];
	}
	else{
	    $parser = SITEMESH_DEFAULT_PAGE_PARSER;
	}
	return new $parser();
    }
    
    
    public function getDecorator(){
	return $this->decorator;
    }
    
    
    public function getDecoratorParams(){
	return $this->decoratorParams;
    }
}


class SiteMesh
{
    private $page;
    private $decorator;
    private $parser;
    private $exclude;
    private $config;
    private $configFactory;

    public function setConfigFactory($configFactory){
	$this->configFactory = $configFactory;
    }

    
    public function decorate(){
	if ($this->configFactory->getExclude()){
	    return;
	}
	
	$parser = $this->configFactory->getParser();
	
	
	$content = ob_get_clean();
	$page = $parser->parse($content);
	
	
	unset($parser);
	
	
	$_decoratorParams = $this->configFactory->getDecoratorParams();
	if ($_decoratorParams){
	    foreach ($_decoratorParams as $name=>$value){
		$$name = $value;
	    }
	}
	
	if (file_exists($this->configFactory->getDecorator())){
	    
	    require($this->configFactory->getDecorator());
	}
	else{
	    $page->printContent();
	}   
    }
    
    
    public function beginCapture() {
	$this->configFactory->parse(SITEMESH_CONFIG_PATH);
	
	if ($this->configFactory->getExclude()){
	    return;
	}
	
	
	ob_start(); 
    }
}


class SiteMeshDefaultPage implements SiteMeshPage
{
    private $headStart;
    private $titleStart;
    private $titleLength;
    private $headBeforeTitleLength;
    private $headAfterTitleStart;
    private $headAfterTitleLength;
    private $bodyStart;
    private $bodyLength;
    protected $content;
    protected $properties;
    
    
    public function __construct($headStart, 
				$headBeforeTitleLength, 
				$titleStart, 
				$titleLength, 
				$headAfterTitleStart, 
				$headAfterTitleLength, 
				$bodyStart, 
				$bodyLength, 
				&$properties,
				&$content){
	
	$this->headStart = $headStart;
	$this->headBeforeTitleLength = $headBeforeTitleLength;
	$this->titleStart = $titleStart;
	$this->titleLength = $titleLength;
	$this->headAfterTitleStart = $headAfterTitleStart;
	$this->headAfterTitleLength = $headAfterTitleLength;
	$this->bodyStart = $bodyStart;
	$this->bodyLength = $bodyLength;
	$this->content = $content;
	$this->properties = $properties;
    }
    
    public function printTitle(){
	echo $this->getTitle();
    }

    public function printHead(){
	echo substr($this->content, $this->headStart, $this->headBeforeTitleLength);
	echo substr($this->content, $this->headAfterTitleStart, $this->headAfterTitleLength); 
    }
    
    public function printBody(){
	echo substr($this->content, $this->bodyStart, $this->bodyLength); 
    }
    
     
    public function printBodyTag($addAttr=null){
	echo '<body';
	if ($addAttr === null){
	    
	    
	    if ($this->properties !== null){
		foreach ($this->properties as $name=>$value){
		    if (strpos($name, 'body.') === 0){
			echo ' ';
			echo substr($name, 5);
			echo '="';
			echo $value;
			echo '"';
		    }
		}
	    }
	}
	else{
	    
	    $attrs = array();
	    if ($this->properties !== null){
		
		
		foreach ($this->properties as $name=>$value){
		    if (strpos($name, 'body.') === 0){
			$name = substr($name, 5);
			$attrs[$name][] = $value;
		    }
		}
	    }
	    
	    foreach ($addAttr as $name=>$value){
		$attrs[$name][] = $value;
	    }
	    
	    foreach ($attrs as $name=>$values){
		echo ' ';
		echo $name;
		echo '="';      
		
		switch ($name){
		case 'class':
		    echo implode(' ', $values);
		    break;
		case 'onload':
		case 'onunload':
		case 'onload':
		case 'onunload':
		case 'onclick':
		case 'ondblclick':
		case 'onmousedown':
		case 'onmouseup':
		case 'onmouseover':
		case 'onmousemove':
		case 'onmouseout':
		case 'onkeypress':
		case 'onkeydown':
		case 'onkeyup':
		case 'style':
		    echo implode('; ', $values);
		    break;
		default:
		    
		    
		    if (count($values) > 1){
			echo $values[1];
		    }
		    else{
			echo $values[0];
		    }
		}
		
		echo '"';
	    }
	}
	echo ">\n";
    }
    
    
    public function includeProperty($name, $defaultValue=null){
	if (isset($this->properties[$name])){
	    $property = $this->properties[$name];
	    if ($property[0] === '/'){
		$property = $_SERVER['DOCUMENT_ROOT'] . $property;
	    }
	    if (!@include($property)){
		echo $defaultValue;
	    }
	}
	else{
	    echo $defaultValue;
	}
    }
    
    public function getTitle(){
	return substr($this->content, $this->titleStart, $this->titleLength); 
    }

    public function &getContent(){
	return $this->content;
    }
    
    public function getProperty($name)  {
	return ((isset($this->properties[$name])) ? $this->properties[$name] : null);
    }

    public function printProperty($name) {
	if (isset($this->properties[$name])){
	    echo $this->properties[$name];
	}
    }
}


class SiteMeshDefaultPageParser implements SiteMeshPageParser{
    private $headStart;
    private $headBeforeTitleEnd;
    private $headBeforeTitleLength;
    private $titleStart;
    private $titleLength;
    private $headAfterTitleStart;
    private $headAfterTitleLength;
    private $bodyStart;
    private $bodyLength;
    protected $properties;
    protected $content;
    protected $pageClass;

    function __construct(){
	$this->pageClass = SITEMESH_DEFAULT_PAGE_CLASS;
    }

    protected function noop($parser, $data, $attr){}
    
    public function setPageClass($pageClass){
	$this->pageClass = $pageClass;
    }

    protected function handleStartElement($parser, $data, $attr) {
	switch ($data){
	case 'head':
	    
	    $this->headStart = xml_get_current_byte_index($parser) + 1;
	    break;
	case 'title':
	    
	    
	    $this->headBeforeTitleEnd = xml_get_current_byte_index($parser);
	    $this->titleStart = $this->headBeforeTitleEnd + 1;
	    break;
	case 'meta':
	    
	    
	    
	    if (!isset($attr['http-equiv']) && strlen($attr['name']) > 0){
		$this->properties['meta.' . $attr['name']] = $attr['content'];
	    }
	    break;
	case 'body':
	    
	    $this->bodyStart = xml_get_current_byte_index($parser) + 1;
	    
	    
	    foreach ($attr as $key=>$val){
		$this->properties['body.' . $key] = $val;
	    }
	    
	    xml_set_element_handler($parser, 'noop', 'handleEndElement');
	    break;
	}
    }
    
    protected function handleEndElement($parser, $data){
	switch ($data){
	case 'head':
	    $this->headBeforeTitleLength = $this->headBeforeTitleEnd - $this->headStart - 6;
	    $this->headAfterTitleLength = xml_get_current_byte_index($parser) - 
		                          $this->headAfterTitleStart - 7;    
	    break;
	case 'title':
	    $this->headAfterTitleStart = xml_get_current_byte_index($parser);
	    $this->titleLength = $this->headAfterTitleStart - $this->titleStart - 8;
	    break;
	case 'body':
	    $this->bodyLength = xml_get_current_byte_index($parser) - $this->bodyStart - 7;
	    break;
	}
    } 
    
    
    public function &parse(&$content){
	$this->content = $content;
	
	
	$parser = xml_parser_create();
	xml_set_object($parser, $this);
	xml_set_element_handler($parser, 'handleStartElement', 'handleEndElement');
	xml_parser_set_option ($parser, XML_OPTION_CASE_FOLDING, false);
	xml_parser_set_option ($parser, XML_OPTION_TARGET_ENCODING, SITEMESH_ENCODING);

	if (xml_parse($parser, $this->content, true)){
	    
	    $page = new $this->pageClass($this->headStart, 
					 $this->headBeforeTitleLength, 
					 $this->titleStart, 
					 $this->titleLength, 
					 $this->headAfterTitleStart, 
					 $this->headAfterTitleLength, 
					 $this->bodyStart, 
					 $this->bodyLength, 
					 $this->properties,
					 $this->content);
	}
	else{
	    
	    $arr = array();
	    $page = new $this->pageClass(0, 0, 0, 0, 0, 0, 0,
					 strlen($this->content), 
					 $arr, $this->content);
	    
	    if (SITEMESH_PAGE_PARSE_DEBUG){
		throw new Exception('SiteMesh parsing error: ' . 
				    xml_error_string(xml_get_error_code($parser)) . 
				    ': Line ' . xml_get_current_line_number($parser) . 
				    ': Column ' . xml_get_current_column_number($parser));
	    }
	}
	
	xml_parser_free($parser);
	
	return $page;
    }
}
?><?php 
$sitemesh = new SiteMesh();
$sitemeshConfigFactory = new SiteMeshConfigFactory();
$sitemeshConfigFactory->setDebug(SITEMESH_CONFIG_PARSE_DEBUG);
$sitemesh->setConfigFactory($sitemeshConfigFactory);
$sitemesh->beginCapture();
?>