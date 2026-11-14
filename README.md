# school
一个php学校网页，没有教务系统，仅前端
本系统原型为东营市东营区行知实验学校开发
现使用Apache 2.0在github开源发布
伪mvc规范，文件名全部小写，单词中间加下划线（部分不遵守此规范）
本项目的前后端分离模式为通过app/model和models加载变量（从mysql或其他）
数据处理等算法在app的【】文件夹
基础设置位于根文件/config的文件，包括数据库，电子邮箱设置等设置
部署注意：
文件权限为app下所有只读（服务器），禁止访问，home文件夹作为入口文件夹，houme\index.php作为入口文件夹
home文件夹开放，但是仍然应该在nginx层面上做保护来防止攻击
密码储存采用哈希的agon2_id算法,因此您的php版本至少应该不低于7.3，我们建议使用php-8.2
项目文件目录解释
SCHOOL
---
 |-controller
 |-app
 |  |-models
 |      |-address.php              学校地址加载[$address]
 |      |-ico.php                  网站图标将从mysql数据库加载（路径）[$ico]
 |      |-                                  