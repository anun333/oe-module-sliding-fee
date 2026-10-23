--
-- Sliding Fee Discount 0.3.0 to 0.4.0: what an assessment is based on, for Title X (42 CFR 59.2):
-- the family's income (as before), a minor's own resources, or the project director counting the
-- patient as low-income. The same block is in install.sql; it is guarded, so running both changes nothing.
--

#IfMissingColumn sliding_fee_assessment basis
ALTER TABLE `sliding_fee_assessment` ADD `basis` VARCHAR(16) NOT NULL DEFAULT 'family' COMMENT 'family, own_resources (a minor assessed on their own resources) or director (counted as low-income by the project director)';
#EndIf
