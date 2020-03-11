#
# Table structure for table 'tx_nrsync_syncstat'
#
CREATE TABLE `tx_nrsync_syncstat` (
    `uid` int(11) NOT NULL auto_increment,
    `pid` int(11) DEFAULT '0' NOT NULL,
    `cruser_id` int(11) unsigned DEFAULT '0' NOT NULL,

    `tab` varchar(128) DEFAULT '' NOT NULL,
    `incr` int(11) unsigned DEFAULT '0' NOT NULL,
    `full` int(11) unsigned DEFAULT '0' NOT NULL,
    `uid_foreign` int(11) unsigned DEFAULT '0' NOT NULL,


    PRIMARY KEY (`uid`),
    KEY `parent` (`pid`),
    UNIQUE element_tab (tab, uid_foreign)
);

#
# Table structure for table 'tx_nrsync_syncstat'
#
CREATE TABLE `tx_nrsync_syncstat_integration` (
    `uid` int(11) NOT NULL auto_increment,
    `pid` int(11) DEFAULT '0' NOT NULL,
    `cruser_id` int(11) unsigned DEFAULT '0' NOT NULL,

    `tab` varchar(128) DEFAULT '' NOT NULL,
    `incr` int(11) unsigned DEFAULT '0' NOT NULL,
    `full` int(11) unsigned DEFAULT '0' NOT NULL,
    `uid_foreign` int(11) unsigned DEFAULT '0' NOT NULL,


    PRIMARY KEY (`uid`),
    KEY `parent` (`pid`),
    UNIQUE element_tab (tab, uid_foreign)
);

#
# Table structure for table 'tx_nrsync_syncstat'
#
CREATE TABLE `tx_nrsync_syncstat_production` (
    `uid` int(11) NOT NULL auto_increment,
    `pid` int(11) DEFAULT '0' NOT NULL,
    `cruser_id` int(11) unsigned DEFAULT '0' NOT NULL,

    `tab` varchar(128) DEFAULT '' NOT NULL,
    `incr` int(11) unsigned DEFAULT '0' NOT NULL,
    `full` int(11) unsigned DEFAULT '0' NOT NULL,
    `uid_foreign` int(11) unsigned DEFAULT '0' NOT NULL,


    PRIMARY KEY (`uid`),
    KEY `parent` (`pid`),
    UNIQUE element_tab (tab, uid_foreign)
);
