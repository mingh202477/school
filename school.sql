-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主机： 127.0.0.1
-- 生成日期： 2026-05-24 05:00:20
-- 服务器版本： 10.4.32-MariaDB
-- PHP 版本： 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `school`
--

-- --------------------------------------------------------

--
-- 表的结构 `banner_images`
--

CREATE TABLE `banner_images` (
  `id` int(11) NOT NULL COMMENT '自增主键',
  `img` varchar(500) NOT NULL COMMENT '图片路径',
  `title` varchar(255) NOT NULL COMMENT '标题文字',
  `link` varchar(500) NOT NULL COMMENT '跳转链接',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序（数值越小越靠前）',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='轮播图表';

--
-- 转存表中的数据 `banner_images`
--

INSERT INTO `banner_images` (`id`, `img`, `title`, `link`, `sort_order`, `created_at`) VALUES
(1, 'public/images/banner1.jpg', '学校全景', '#', 1, '2026-05-23 18:47:36'),
(2, 'public/images/banner2.jpg', '科技创新', '#', 2, '2026-05-23 18:47:36'),
(3, 'public/images/banner3.jpg', '人才培养', '#', 3, '2026-05-23 18:47:36');

-- --------------------------------------------------------

--
-- 表的结构 `config`
--

CREATE TABLE `config` (
  `id` int(20) DEFAULT NULL,
  `name` varchar(500) NOT NULL,
  `url` varchar(300) DEFAULT NULL,
  `description` varchar(500) NOT NULL,
  `introduction` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='comfig网站基础配置';

--
-- 转存表中的数据 `config`
--

INSERT INTO `config` (`id`, `name`, `url`, `description`, `introduction`) VALUES
(1, 'title', NULL, '东营区行知实验学校', 'title网站标题'),
(2, 'keywords', NULL, '东营区行知实验学校', 'keywords seo收录（id=2）'),
(3, 'logo_1', 'public/images/logo1.png', 'null', 'logo图片1（格式建议png）要求正方形建议放校徽'),
(4, 'logo_2', 'public/images/logo2.png', 'null', 'logo_2 长方形logo'),
(5, 'ico', 'dyxz.ico', 'null', '浏览器图标后缀.ico（id=5）'),
(6, 'icp', NULL, 'f', 'icp备案号没有在字段“description”填写“f”（必须小写！）（id=6）'),
(7, 'address', NULL, '地址', '地址（address）（没有填写小写\'f\'）（id=7）'),
(8, 'postal_code', NULL, '邮编号码', 'postal_code邮编号码 （没有填小写f）');

-- --------------------------------------------------------

--
-- 表的结构 `media_coverage`
--

CREATE TABLE `media_coverage` (
  `id` int(11) NOT NULL COMMENT '自增主键',
  `title` varchar(255) NOT NULL COMMENT '报道标题',
  `coverage_date` date NOT NULL COMMENT '报道日期，格式 YYYY-MM-DD',
  `link` varchar(500) NOT NULL COMMENT '报道链接',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '记录创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='学校媒体报道表';

--
-- 转存表中的数据 `media_coverage`
--

INSERT INTO `media_coverage` (`id`, `title`, `coverage_date`, `link`, `created_at`) VALUES
(1, '人民日报报道我校科研成果', '2026-05-10', '#', '2026-05-23 18:25:39'),
(2, '央视新闻关注学校创新人才培养', '2026-05-09', '#', '2026-05-23 18:25:39'),
(3, '新华社聚焦学校科技创新', '2026-05-08', '#', '2026-05-23 18:25:39'),
(4, '中国教育报专题报道', '2026-05-07', '#', '2026-05-23 18:25:39');

-- --------------------------------------------------------

--
-- 表的结构 `nav_links`
--

CREATE TABLE `nav_links` (
  `id` int(11) NOT NULL COMMENT '自增主键',
  `parent_id` int(11) DEFAULT NULL COMMENT '父菜单ID，NULL表示顶级菜单',
  `text` varchar(255) NOT NULL COMMENT '菜单文字',
  `url` varchar(500) NOT NULL COMMENT '链接地址',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序（同级内越小越靠前）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='导航菜单表';

--
-- 转存表中的数据 `nav_links`
--

INSERT INTO `nav_links` (`id`, `parent_id`, `text`, `url`, `sort_order`) VALUES
(1, NULL, '学校概况', '#', 1),
(2, NULL, '组织机构', '#', 2),
(3, NULL, '人才培养', '#', 3),
(4, NULL, '师资队伍', '#', 4),
(5, NULL, '科学研究', '#', 5),
(6, NULL, '招生就业', '#', 6),
(7, NULL, '合作交流', '#', 7),
(8, NULL, '人才招聘', '#', 8),
(9, 1, '学校简介', '#', 1),
(10, 1, '学校章程', '#', 2),
(11, 1, '现任领导', '#', 3),
(12, 1, '历史沿革', '#', 4),
(13, 2, '党政机关', '#', 1),
(14, 2, '教学单位', '#', 2),
(15, 2, '直属单位', '#', 3),
(16, 3, '本科生教育', '#', 1),
(17, 3, '研究生教育', '#', 2),
(18, 3, '国际教育', '#', 3),
(19, 4, '院士风采', '#', 1),
(20, 4, '教师主页', '#', 2),
(21, 5, '科研平台', '#', 1),
(22, 5, '科研成果', '#', 2),
(23, 6, '本科招生', '#', 1),
(24, 6, '研究生招生', '#', 2),
(25, 6, '就业信息', '#', 3);

-- --------------------------------------------------------

--
-- 表的结构 `news`
--

CREATE TABLE `news` (
  `id` int(30) NOT NULL,
  `title` varchar(500) NOT NULL,
  `username` varchar(100) NOT NULL,
  `date` varchar(300) NOT NULL,
  `url` varchar(100) DEFAULT NULL,
  `text` text DEFAULT NULL COMMENT '文本html格式',
  `people` int(100) DEFAULT NULL COMMENT '浏览人数',
  `froma` varchar(200) NOT NULL COMMENT '来源（必填）'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='news新闻';

-- --------------------------------------------------------

--
-- 表的结构 `portal_cards`
--

CREATE TABLE `portal_cards` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `desc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`desc`)),
  `class` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转存表中的数据 `portal_cards`
--

INSERT INTO `portal_cards` (`id`, `title`, `desc`, `class`) VALUES
(1, '数字校园', '[\"校园门户\",\"电子邮件\",\"办公系统\",\"校园公告\"]', 'portal-blue'),
(2, '本科生招生', '[\"招生简章\",\"专业目录\",\"招生动态\"]', 'portal-sky'),
(3, '研究生招生', '[\"招生信息\",\"推免系统\",\"报名平台\"]', 'portal-sky2'),
(4, '学习教育', '[\"专题学习\",\"主题教育\",\"党建专栏\"]', 'portal-red'),
(5, '学术科研', '[\"科研平台\",\"重点实验室\",\"成果展示\"]', 'portal-science'),
(6, '文化校园', '[\"校史馆\",\"形象识别\",\"校园文化\"]', 'portal-indigo'),
(7, '全媒体矩阵', '[\"微博\",\"微信\",\"视频号\",\"B站\"]', 'portal-media'),
(8, '校园服务', '[\"信息公开\",\"校园地图\",\"后勤服务\"]', 'portal-service'),
(9, '“十五五”规划专题', '[\"规划专题\",\"重点任务\",\"建言献策\"]', 'portal-plan');

-- --------------------------------------------------------

--
-- 表的结构 `top_links`
--

CREATE TABLE `top_links` (
  `id` int(11) NOT NULL COMMENT '自增主键',
  `text` varchar(255) NOT NULL COMMENT '链接文字',
  `url` varchar(500) NOT NULL COMMENT '链接地址',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序（越小越靠前）',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='顶部导航链接表';

--
-- 转存表中的数据 `top_links`
--

INSERT INTO `top_links` (`id`, `text`, `url`, `sort_order`, `created_at`) VALUES
(1, '学生', '#', 1, '2026-05-23 18:37:03'),
(2, '教工', '#', 2, '2026-05-23 18:37:03'),
(3, '校友', '#', 3, '2026-05-23 18:37:03'),
(4, '访客', '#', 4, '2026-05-23 18:37:03');

-- --------------------------------------------------------

--
-- 表的结构 `xueshu_activities`
--

CREATE TABLE `xueshu_activities` (
  `id` int(11) NOT NULL COMMENT '自增主键',
  `title` varchar(255) NOT NULL COMMENT '活动标题',
  `activity_date` date NOT NULL COMMENT '活动日期',
  `link` varchar(500) NOT NULL COMMENT '详情链接',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '附加排序',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '记录创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='学术活动表';

--
-- 转存表中的数据 `xueshu_activities`
--

INSERT INTO `xueshu_activities` (`id`, `title`, `activity_date`, `link`, `sort_order`, `created_at`) VALUES
(1, '人工智能前沿论坛举行', '2026-05-10', '#', 1, '2026-05-23 18:55:45'),
(2, '航空航天学术报告会', '2026-05-09', '#', 2, '2026-05-23 18:55:45'),
(3, '高端装备制造交流会', '2026-05-08', '#', 3, '2026-05-23 18:55:45'),
(4, '青年学者论坛举办', '2026-05-07', '#', 4, '2026-05-23 18:55:45');

-- --------------------------------------------------------

--
-- 表的结构 `yaowen_list`
--

CREATE TABLE `yaowen_list` (
  `id` int(11) NOT NULL COMMENT '自增主键',
  `img` varchar(500) NOT NULL COMMENT '图片路径',
  `title` varchar(255) NOT NULL COMMENT '标题',
  `desc_text` varchar(500) NOT NULL COMMENT '简短描述',
  `link` varchar(500) NOT NULL COMMENT '详情链接',
  `publish_date` date NOT NULL COMMENT '发布日期',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '附加排序',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '记录创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='要闻列表';

--
-- 转存表中的数据 `yaowen_list`
--

INSERT INTO `yaowen_list` (`id`, `img`, `title`, `desc_text`, `link`, `publish_date`, `sort_order`, `created_at`) VALUES
(1, 'public/images/yw1.jpg', '学校召开重点工作推进会', '进一步推动学校高质量发展', '#', '2026-05-24', 1, '2026-05-23 18:52:10'),
(2, 'public/images/yw2.jpg', '科技创新成果发布', '多项关键技术取得突破', '#', '2026-05-22', 2, '2026-05-23 18:52:10'),
(3, 'public/images/yw3.jpg', '校园文化艺术节开幕', '丰富校园文化生活', '#', '2026-05-20', 3, '2026-05-23 18:52:10'),
(4, 'public/images/yw4.jpg', '国际合作交流项目启动', '深化国际合作交流', '#', '2026-05-18', 4, '2026-05-23 18:52:10');

--
-- 转储表的索引
--

--
-- 表的索引 `banner_images`
--
ALTER TABLE `banner_images`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `config`
--
ALTER TABLE `config`
  ADD UNIQUE KEY `id` (`id`);

--
-- 表的索引 `media_coverage`
--
ALTER TABLE `media_coverage`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `nav_links`
--
ALTER TABLE `nav_links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- 表的索引 `news`
--
ALTER TABLE `news`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`);

--
-- 表的索引 `portal_cards`
--
ALTER TABLE `portal_cards`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `top_links`
--
ALTER TABLE `top_links`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `xueshu_activities`
--
ALTER TABLE `xueshu_activities`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `yaowen_list`
--
ALTER TABLE `yaowen_list`
  ADD PRIMARY KEY (`id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `banner_images`
--
ALTER TABLE `banner_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键', AUTO_INCREMENT=4;

--
-- 使用表AUTO_INCREMENT `media_coverage`
--
ALTER TABLE `media_coverage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键', AUTO_INCREMENT=5;

--
-- 使用表AUTO_INCREMENT `nav_links`
--
ALTER TABLE `nav_links`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键', AUTO_INCREMENT=26;

--
-- 使用表AUTO_INCREMENT `news`
--
ALTER TABLE `news`
  MODIFY `id` int(30) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `portal_cards`
--
ALTER TABLE `portal_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- 使用表AUTO_INCREMENT `top_links`
--
ALTER TABLE `top_links`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键', AUTO_INCREMENT=5;

--
-- 使用表AUTO_INCREMENT `xueshu_activities`
--
ALTER TABLE `xueshu_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键', AUTO_INCREMENT=5;

--
-- 使用表AUTO_INCREMENT `yaowen_list`
--
ALTER TABLE `yaowen_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键', AUTO_INCREMENT=5;

--
-- 限制导出的表
--

--
-- 限制表 `nav_links`
--
ALTER TABLE `nav_links`
  ADD CONSTRAINT `nav_links_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `nav_links` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
