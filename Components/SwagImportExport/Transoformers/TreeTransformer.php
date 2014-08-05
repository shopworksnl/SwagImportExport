<?php

namespace Shopware\Components\SwagImportExport\Transoformers;

/**
 * The responsibility of this class is to restructure the flat array to tree and vise versa
 */
class TreeTransformer implements DataTransformerAdapter
{

    protected $config;

    /**
     * @var array
     */
    protected $iterationPart;

    /**
     * @var array
     */
    protected $headerFooterData;
    
    //import properties
    protected $mainType;
    protected $rawData;
    protected $bufferData;
    protected $importMapper;
    
    //export properties
    protected $data;
    protected $currentRecord;
    protected $preparedData;

    /**
     * Sets the config that has the tree structure
     */
    public function initialize($config)
    {
        $this->config = $config;
    }

    /**
     * Transforms the flat array into tree with list of nodes containing children and attributes.
     */
    public function transformForward($data)
    {
        $this->setData($data);
        $transformData = array();
               
        $iterationPart = $this->getIterationPart();
        
        $adapter = $iterationPart['adapter'];
        
        foreach ($data[$adapter] as $record) {
            $this->currentRecord = $record;
            $transformData[] = $this->transformToTree($iterationPart, $record, $adapter);
            unset($this->currentRecord);
        }
        
        //creates iteration array
        $treeBody = array($iterationPart['name'] => $transformData);
        
        return $treeBody;
        
    }
    
    /**
     * Transforms a list of nodes containing children and attributes into flat array.
     */
    public function transformBackward($data)
    {
        //gets iteration nodes
        $this->getIterationNodes();
        
        $iterationPart = $this->getIterationPart();
        $this->mainType = $iterationPart['adapter'];
        
        $this->buildRawData($data, $iterationPart['adapter']);
        
        return $this->getRawData();
    }
    
    /**
     * Helper method which creates iteration nodes array structure
     * 
     * @param array $node
     * @return array
     * @throws \Exception
     */
    public function buildIterationNode($node)
    {
        $transformData = array();
        
        if ($this->currentRecord === null) {
            throw new \Exception('Current record was not found');
        }
        
        if (!isset($node['adapter'])) {
            throw new \Exception('Adapter was not found');            
        }
        
        if (!isset($node['parentKey'])) {
            throw new \Exception('Parent key was not found');            
        }
        
        $type = $node['adapter'];
        $parentKey = $node['parentKey'];
        
        //gets connections between interation nodes
        $recordLink = $this->currentRecord[$parentKey];
        
        //prepares raw data
        $data = $this->getPreparedData($type, $parentKey);
        
        //gets records for the current iteration node
        $records = $data[$recordLink];
        
        if (!$records) {
            $transformData[] = $this->transformToTree($node, null, $type);
        } else {
            foreach ($records as $record) {
                $transformData[] = $this->transformToTree($node, $record, $type);
            }            
        }

                
        return $transformData;
    }
    
    /**
     * Helper method which creates rawData from array structure
     * 
     * @param array $data
     * @param string $type
     * @param string $nodePath
     */
    public function buildRawData($data, $type, $nodePath = null)
    {
        //creates import mapper 
        //["Prices_Price_groupName"]=> "pricegroup"
        $importMapper = $this->getImportMapper();
        
        foreach ($data as $record) {
            $this->transformFromTree($record, $importMapper, $type, $nodePath);
            $bufferData = $this->getBufferData($type);
            
            if ($this->getMainType() !== $type) {
                $rawData = $this->getRawData();
                $partentElement = count($rawData[$this->getMainType()]);
                $bufferData['parentIndexElement'] = $partentElement;                
            }
            
            $this->setRawData($type, $bufferData);
            $this->unsetBufferData($type);
        }
    }
    
    /**
     * Get iteration nodes from profile
     */
    public function getIterationNodes()
    {
        $iterationPart = $this->getIterationPart();
        
        $this->createIterationNodeMapper($iterationPart);
    }
    
    /**
     * Composes a tree header based on config
     */
    public function composeHeader()
    {
        $data = $this->getHeaderAndFooterData();

        return $data;
    }

    /**
     * Composes a tree footer based on config
     */
    public function composeFooter()
    {
        $data = $this->getHeaderAndFooterData();

        return $data;
    }

    /**
     * Parses a tree header based on config
     */
    public function parseHeader($data)
    {
        
    }

    /**
     * Parses a tree footer based on config
     */
    public function parseFooter($data)
    {
        
    }

    /**
     * Preparing/Modifying nodes to converting into xml
     * and puts db data into this formated array
     * 
     * @param array $node
     * @param array $mapper
     * @return array
     */
    public function transformToTree($node, $mapper = null, $adapterType = null)
    {
        if (isset($node['adapter']) && $node['adapter'] != $adapterType) {
            $currentNode = $this->buildIterationNode($node);
            
            return $currentNode;
        }

        if (isset($node['children'])) {
            if (isset($node['attributes'])) {
                foreach ($node['attributes'] as $attribute) {
                    $currentNode['_attributes'][$attribute['name']] = $mapper[$attribute['shopwareField']];
                }
            }

            foreach ($node['children'] as $child) {
                $currentNode[$child['name']] = $this->transformToTree($child, $mapper, $node['adapter']);
            }
        } else {
            if (isset($node['attributes'])) {
                foreach ($node['attributes'] as $attribute) {
                    $currentNode['_attributes'][$attribute['name']] = $mapper[$attribute['shopwareField']];
                }

                $currentNode['_value'] = $mapper[$node['shopwareField']];
            } else {
                $currentNode = $mapper[$node['shopwareField']];
            }
        }
        
        return $currentNode;
    }
    
    /**
     * Transforms read it data into raw data
     * 
     * @param array $node
     * @param array $importMapper
     * @param string $adapter
     * @param string $nodePath
     * @return
     */
    public function transformFromTree($node, $importMapper, $adapter, $nodePath = null)
    {
        $separator = null;
        
        if ($nodePath) {
            $separator = '_';
        }
        
        if(isset($this->iterationNodes[$nodePath]) && $adapter !== $this->iterationNodes[$nodePath]){
            $this->buildRawData($node, $this->iterationNodes[$nodePath], $nodePath, true);
            return;
        }
        
        if (isset($node['_value'])) {
            if (isset($importMapper[$nodePath])) {
                $this->saveBufferData($adapter, $importMapper[$nodePath], $node['_value']);
            }

            if (isset($node['_attributes'])) {
                
                $this->transformFromTree($node['_attributes'], $importMapper, $adapter, $nodePath);
            }
        } else if (is_array($node)) {
            foreach ($node as $key => $child) {
                if($key !== '_attributes'){
                    $currentNode = $nodePath . $separator . $key;                    
                } else {
                    $currentNode = $nodePath;
                }
                
                $this->transformFromTree($child, $importMapper, $adapter, $currentNode);
            }
        } else {
            if (isset($importMapper[$nodePath])) {
                $this->saveBufferData($adapter, $importMapper[$nodePath], $node);                
            }
        }
    }
    
    /**
     * Search the iteration part of the tree template
     * 
     * @param array $tree
     */
    public function findIterationPart(array $tree)
    {
        foreach ($tree as $key => $value) {
            if ($key === 'adapter') {
                $this->iterationPart = $tree;
                return;
            }
            
            if (is_array($value)) {
                $this->findIterationPart($value);
            }
        }

        return;
    }

    /**
     * Returns the iteration part of the tree
     * 
     * @return array
     */
    public function getIterationPart()
    {
        if ($this->iterationPart == null) {
            $tree = json_decode($this->config, true);
            $this->findIterationPart($tree);
        }

        return $this->iterationPart;
    }

    public function getHeaderAndFooterData()
    {
        if ($this->headerFooterData === null) {
            $tree = json_decode($this->config, true);
            //replaceing iteration part with custom marker
            $this->removeIterationPart($tree);
            
            $modifiedTree = $this->transformToTree($tree);
            
            if (!isset($tree['name'])) {
                throw new \Exception('Root category in the tree does not exists');
            }
            
            $this->headerFooterData = array($tree['name'] => $modifiedTree);
        }
        
        return $this->headerFooterData;
    }

    /**
     * Returns import mapper
     * 
     * @return array
     */
    public function getImportMapper()
    {
        if ($this->importMapper === null) {
            $iterationPart = $this->getIterationPart();
            $this->generateMapper($iterationPart);
        }

        return $this->importMapper;
    }

    /**
     * @param string $key
     * @param string $value
     * @throws \Exception
     */
    public function saveMapper($key, $value)
    {
        $this->importMapper[$key] = $value;
    }

    /**
     * @param string $key
     * @param string $value
     * @throws \Exception
     */
    public function saveBufferData($adapter, $key, $value)
    {
        $this->bufferData[$adapter][$key] = $value;
    }
    
    public function getRawData()
    {
        return $this->rawData;
    }

    public function setRawData($type, $rawData)
    {
        $this->rawData[$type][] = $rawData;
    }
    
    public function getBufferData($type)
    {
        return $this->bufferData[$type];
    }

    public function setBufferData($bufferData)
    {
        $this->bufferData = $bufferData;
    }
    
    public function unsetBufferData($type)
    {
        unset($this->bufferData[$type]);
    }
        
    public function getData()
    {
        return $this->data;
    }

    public function setData($data)
    {
        $this->data = $data;
    }
    
    public function getMainType()
    {
        return $this->mainType;
    }

    public function setMainType($mainType)
    {
        $this->mainType = $mainType;
    }

        
    public function getPreparedData($type, $recordLink)
    {
        if ($this->preparedData[$type] === null) {
                        
            $data = $this->getData();

            foreach ($data[$type] as $record) {
                $this->preparedData[$type][$record[$recordLink]][] = $record;
            }           
        }
        
        return $this->preparedData[$type];
    }
    
    /**
     * Create iteration node mapper for import
     * 
     * @param mixed $node
     */
    protected function createIterationNodeMapper($node, $nodePath = null)
    {
        $separator = null;
        if (isset($node['adapter']) && $nodePath) {
            $this->iterationNodes[$nodePath] = $node['adapter'];
        }
        
        if (isset($node['children'])) {

            foreach ($node['children'] as $child) {
                if ($nodePath) {
                    $separator = '_';
                } 
                $currentPath = $nodePath . $separator .$child['name'];
                $this->createIterationNodeMapper($child, $currentPath);
            }
        }
    }
        
    /**
     * Generates import mapper from the js tree
     * 
     * @param mixed $node
     */
    protected function generateMapper($node, $nodePath = null)
    {
        $separator = null;
        
        if ($nodePath) {
            $separator = '_';
        }       
        
        if (isset($node['children'])) {

            if (isset($node['attributes'])) {
                foreach ($node['attributes'] as $attr) {
                    $currentPath = $nodePath . $separator .$attr['name'];
                    $this->saveMapper($currentPath, $attr['shopwareField']);
                }
            }

            foreach ($node['children'] as $child) {
                $currentPath = $nodePath . $separator .$child['name'];
                $this->generateMapper($child, $currentPath);
            }
        } else {
            if (isset($node['attributes'])) {
                foreach ($node['attributes'] as $attr) {
                    $currentPath = $nodePath . $separator .$attr['name'];
                    $this->saveMapper($currentPath, $attr['shopwareField']);
                }
            }
            
            $this->saveMapper($nodePath, $node['shopwareField']);
        }
    }

    /**
     * Replace the iteration part with custom tag "_currentMarker"
     * 
     * @param mixed $node
     */
    protected function removeIterationPart(&$node)
    {
        if (isset($node['adapter'])) {
            $node = array('name' => '_currentMarker');
        }

        if (isset($node['children'])) {
            foreach ($node['children'] as &$child) {
                $this->removeIterationPart($child);
            }
        }
    }

}