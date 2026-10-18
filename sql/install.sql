--
-- Sliding Fee Discount module tables.
--
-- The schedule is kept as each saved version (the newest is in force), and every
-- income assessment is kept: HRSA's Compliance Manual, Chapter 9, element g, asks for
-- records of assessing and re-assessing each patient.
--

#IfNotTable sliding_fee_schedule
CREATE TABLE `sliding_fee_schedule` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `schedule_json` TEXT NOT NULL COMMENT 'Schedule::toArray()',
    `saved_by` INT(11) NOT NULL COMMENT 'users.id',
    `saved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB COMMENT='Sliding Fee Discount: each saved version of the schedule; the newest is in force';
#EndIf

#IfNotTable sliding_fee_assessment
CREATE TABLE `sliding_fee_assessment` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL,
    `assessed_on` DATE NOT NULL,
    `method` VARCHAR(16) NOT NULL COMMENT 'documents, self_declared or declined',
    `annual_income` DECIMAL(12,2) DEFAULT NULL,
    `family_size` INT(11) DEFAULT NULL,
    `percent` DECIMAL(7,2) DEFAULT NULL COMMENT 'of the poverty guideline, when assessed',
    `class_label` VARCHAR(32) NOT NULL COMMENT 'pay class when assessed',
    `schedule_id` INT(11) DEFAULT NULL COMMENT 'sliding_fee_schedule.id in force when assessed',
    `note` TEXT,
    `user_id` INT(11) NOT NULL COMMENT 'users.id who recorded it',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pid_assessed` (`pid`, `assessed_on`)
) ENGINE=InnoDB COMMENT='Sliding Fee Discount: each income assessment of a patient';
#EndIf

-- The Adjustment Reason to post discounts under, as a charge adjustment (option_value 1),
-- unless the clinic already has one by that name.
#IfNotRow2D list_options list_id adjreason option_id Sliding fee discount
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `option_value`, `activity`)
    VALUES ('adjreason', 'Sliding fee discount', 'Sliding fee discount', 32, 1, 1);
#EndIf
