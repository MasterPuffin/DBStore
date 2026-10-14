<?php

namespace MasterPuffin\DBStore;

enum VarType: string {
	// Numeric Types
	case TINYINT = 'TINYINT';
	case SMALLINT = 'SMALLINT';
	case MEDIUMINT = 'MEDIUMINT';
	case INT = 'INT';
	case INTEGER = 'INTEGER';
	case BIGINT = 'BIGINT';
	case DECIMAL = 'DECIMAL';
	case NUMERIC = 'NUMERIC';
	case FLOAT = 'FLOAT';
	case DOUBLE = 'DOUBLE';
	case DOUBLE_PRECISION = 'DOUBLE PRECISION';
	case BIT = 'BIT';
	case BOOL = 'BOOL';
	case BOOLEAN = 'BOOLEAN';

	// Date and Time Types
	case DATE = 'DATE';
	case DATETIME = 'DATETIME';
	case TIMESTAMP = 'TIMESTAMP';
	case TIME = 'TIME';
	case YEAR = 'YEAR';

	// String Types
	case CHAR = 'CHAR';
	case VARCHAR = 'VARCHAR';
	case BINARY = 'BINARY';
	case VARBINARY = 'VARBINARY';
	case TINYBLOB = 'TINYBLOB';
	case BLOB = 'BLOB';
	case MEDIUMBLOB = 'MEDIUMBLOB';
	case LONGBLOB = 'LONGBLOB';
	case TINYTEXT = 'TINYTEXT';
	case TEXT = 'TEXT';
	case MEDIUMTEXT = 'MEDIUMTEXT';
	case LONGTEXT = 'LONGTEXT';
	case ENUM = 'ENUM';
	case SET = 'SET';

	// Spatial Types
	case GEOMETRY = 'GEOMETRY';
	case POINT = 'POINT';
	case LINESTRING = 'LINESTRING';
	case POLYGON = 'POLYGON';
	case MULTIPOINT = 'MULTIPOINT';
	case MULTILINESTRING = 'MULTILINESTRING';
	case MULTIPOLYGON = 'MULTIPOLYGON';
	case GEOMETRYCOLLECTION = 'GEOMETRYCOLLECTION';

	// JSON Type
	case JSON = 'JSON';
}