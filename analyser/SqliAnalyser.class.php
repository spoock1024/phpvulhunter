<?php

/**
 * 进行sql注入的漏洞分析
 * @author Exploit
 *
 */
class SqliAnalyser {	
	
	/**
	 * 判断变量的净化情况
	 * 返回:
	 * 		(1)如果没有有效的净化，返回false
	 * 		(2)如果进行了有效的净化，返回true
	 * 		(3)如果净化数组为null,返回false
	 * @param symbol $var  判断的变量
	 * @param array $saniArr  判断的净化数组
	 * @return bool
	 */
	private function check_sanitization($var,$saniArr){
		//如果数组为空，说明没有进行任何净化
		if(count($saniArr) == 0){
			return false ;
		}
		//数值型注入，转义无效
		if($var->getType() == "int" && in_array("addslashes", $saniArr)){
			return false ;
		}
		
		return true ;
	}
	
	
	/**
	 * 根据变量的净化栈和编码栈判断是否是有效净化和编码
	 * 返回:
	 * 		(1)true 	=> 有效净化
	 * 		(2)false 	=> 无效净化
	 * @param array $saniArr
	 * @param array $encodingArr
	 * @return bool
	 */
	public function analyse($var,$saniArr,$encodingArr){
		//处理编码
		AnalyseUtils::initSaniti($saniArr) ;
		AnalyseUtils::initEncodeList($encodingArr) ;
		
		//编码和净化的判别
		if(AnalyseUtils::check_encoding($encodingArr) == true){
			//编码正确情况下，净化不够
			if($this->check_sanitization($var, $saniArr) == false){
				return false ;
			}else{
				return true ;
			}
		}else if(AnalyseUtils::check_encoding($encodingArr) == false){
			return false ;
		}
		
	}
	
	
}









?>