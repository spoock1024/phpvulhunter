<?php
/**
 * 用于污点分析的类
 * 污点分析的任务：
 * 	（1）从各个基本块摘要中找出危险参数的变化
 * 	（2）评估危险参数是否受到有效净化
 * 	（3）根据评估结果报告漏洞
 * @author Exploit
 *
 */
class TaintAnalyser {

	//方法getPrevBlocks返回的参数
	private $pathArr = array() ;
	public function getPathArr() {
		return $this->pathArr;
	}

	private $sourcesArr = array() ;
	
	public function __construct(){
		$this->sourcesArr = Sources::getUserInput() ;
	}
	
	
	

	/**
	 * 获取当前基本块的所有前驱基本块
	 * @param BasicBlock $block
	 * @return Array 返回前驱基本块集合
	 */
	public function getPrevBlocks($currBlock){
		if($currBlock != null){
			$blocks = array() ;
			$edges = $currBlock->getInEdges();
			
			//如果到达了第一个基本块则返回
			if(!$edges) return $this->pathArr;
			
			foreach ($edges as $edge){
				array_push($blocks, $edge->getSource()) ;
			}
			
			if(count($blocks) == 1){
				//前驱的节点只有一个
				if(!in_array($blocks[0],$this->pathArr)){
					array_push($this->pathArr,$blocks[0]) ;
				} 
			}else{
				//前驱节点有多个
				if(!in_array($blocks,$this->pathArr)){
					array_push($this->pathArr,$blocks) ;
				} 
			}
		
			//递归
			foreach($blocks as $bitem){
				if(!is_array($bitem)){
					$this->getPrevBlocks($bitem);
				}else{
					$this->getPrevBlocks($bitem[0]) ;
				}
				
			}
		
		}
	}
	
	/**
	 * 污点分析中，对当前基本块的探测
	 * @param BasicBlock $block  当前基本块
	 * @param Node $node  当前调用sink的node
	 * @param string $argName  危险参数的名称
	 * @param int $flowsNum 数据流的数量，用于清除分析的flow
	 */
	public function currBlockTaintHandler($block,$node,$argName,$flowsNum=0){
		//获取数据流信息
		$flows = $block->getBlockSummary() ->getDataFlowMap() ;
		$flows = array_reverse($flows); //逆序处理flows

		// 去掉分析过的$flow
		$temp = $flowsNum ;
		while ($temp > 0){
			array_pop($flows) ;
			$temp -- ;
		}
		
		foreach ($flows as $flow){
			$flowsNum ++ ;
			if($flow->getName() == $argName){
				//处理净化信息,如果被编码或者净化则返回safe
				//被isSanitization函数取代
				if ($flow && $flow->getLocation()->getSanitization()){
					return "safe";
				}
				
				//获取flow中的右边赋值变量
				//得到flow->getValue()的变量node
				//$sql = $a . $b ;  =>  array($a,$b)
				if($flow->getValue() instanceof ConcatSymbol){
					$vars = $flow->getValue()->getItems();
				}else{
					$vars = array($flow->getValue()) ;
				}
				
				$retarr = array();
				foreach($vars as $var){
					$varName = NodeUtils::getNodeStringName($var) ;
					$ret = $this->currBlockTaintHandler($block, $node, $varName,$flowsNum) ;
					//变量经过净化，这不需要跟踪该变量
					if ($ret == "safe"){
						$retarr = array_slice($retarr, array_search($varName,$retarr)) ;
					}else{
						//如果var右边有source项
						if(in_array($varName, $this->sourcesArr)){
							//报告漏洞
							$this->report($node, $flow->getLocation()) ;
							return true ;
						}
					}
				}

			}
		}
	}
	
	
	/**
	 * 处理多个block的情景
	 * @param BasicBlock $block 当前基本块
	 * @param string $argName 敏感参数名
	 * @param Node $node 调用sink的nodeo 
	 */
	public function multiBlockHandler($block,$argName,$node,$flowsNum=0){
		
		if($this->pathArr){
			$this->pathArr = array() ;
		} 
		$this->getPrevBlocks($block) ;
		$block_list = $this->pathArr ;
 		
		if($block_list == null || count($block_list) == 0){
			return  ;
		}

		if(!is_array($block_list[0])){
			//如果不是平行结构
			$flows = $block_list[0]->getBlockSummary()->getDataFlowMap() ;
			$flows = array_reverse($flows) ;
		
			//对于每个flow,寻找变量argName
			foreach ($flows as $flow){
				if($flow->getName() == $argName){
					//处理净化信息,如果被编码或者净化则返回safe
					//被isSanitization函数取代
					if ($flow && $flow->getLocation()->getSanitization()){
						return "safe";
					}
				
					//获取flow中的右边赋值变量
					//得到flow->getValue()的变量node
					//$sql = $a . $b ;  =>  array($a,$b)
					if($flow->getValue() instanceof ConcatSymbol){
						$vars = $flow->getValue()->getItems();
					}else{
						$vars = array($flow->getValue()) ;
					}
					
					$retarr = array();
					foreach($vars as $var){
						$varName = NodeUtils::getNodeStringName($var) ;
						//print_r($block_list[0]) ;
						$ret = $this->multiBlockHandler($block_list[0], $varName, $node,$flowsNum) ;
						array_shift($block_list) ;
						//变量经过净化，这不需要跟踪该变量
						if ($ret == "safe"){
							$retarr = array_slice($retarr, array_search($varName,$retarr)) ;
						}else{
							//如果var右边有source项
							if(in_array($varName, $this->sourcesArr)){
								//报告漏洞
								$this->report($node, $flow->getLocation()) ;
								return true ;
							}
						}
					}
				
				}
			}
			
		}else{
			//是平行结构
		}
		
	}
	
	/**
	 * 根据sink的类型、危险参数的净化信息列表、编码列表
	 * 判断是否是有效的净化
	 * 返回true or false
	 * 'XSS','SQLI','HTTP','CODE','EXEC','LDAP','INCLUDE','FILE','XPATH','FILEAFFECT'
	 * @param string $type 漏洞的类型，使用TypeUtils可以获取
	 * @param array $saniArr 危险参数的净化信息栈
	 * @param array $encodingArr 危险参数的编码信息栈
	 */
	public function isSanitization($type,$saniArr,$encodingArr){
		switch ($type){
			case 'SQLI':
				break ;
			case 'XSS':
				break ;
			case 'HTTP':
				break ;
			case 'CODE':
				break ;
			case 'EXEC':
				break ;
			case 'LDAP':
				break ;
			case 'INCLUDE':
				break ;
			case 'FILE':
				break ;
			case 'XPATH':
				break ;
			case 'FILEAFFECT':
				break ;
		}
	}
	
	
	/**
	 * 污点分析的函数
	 * @param BasicBlock $block 当前基本块
	 * @param Node $node 当前的函数调用node
	 * @param string $argName 危险参数名
	 */
	public function analysis($block,$node,$argName){
		
		//获取前驱基本块集合并将当前基本量添加至列表
		$block_list = $this->pathArr ;
		array_push($block_list, $block) ;
		//首先，在当前基本块中探测变量，如果有source和不完整的santi则报告漏洞
		//$ret = $this->currBlockTaintHandler($block, $node, $argName) ;
		//if($ret === true) return ;

		//遍历每个前驱block
// 		foreach($block_list as $bitem){
// 			//不是平行结构
// 			if(!is_array($bitem)){
// 				$ret = $this->currBlockTaintHandler($bitem, $node, $argName) ;
// 				if($ret === true) return ;
// 			}else{
// 				//是平行结构，比如if-else
// 				foreach($bitem as $branch){
// 					$ret = $this->currBlockTaintHandler($branch, $node, $argName) ;
// 					if($ret === true) return ;
// 				}
// 			}
// 		}
		$this->pathArr = array() ;
		$this->multiBlockHandler($block, $argName, $node) ;
		
		return array() ;
	}
	
	
	/**
	 * 报告漏洞的函数
	 * @param Node $node 出现漏洞的node
	 * @param Node $var  出现漏洞的变量node
	 */
	public function report($node,$var){
		echo "<pre>" ;
		echo "有漏洞！！！！<br/>" ;
		echo "漏洞变量：<br/>" ;
		print_r($var) ;
		echo "漏洞节点：<br/>" ;
		print_r($node) ;
	}
	
	
}



?>