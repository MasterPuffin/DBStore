<?php

namespace MasterPuffin\DBStore;

enum Constraint {
	case Cascade;
	case SetNull;
	case NoAction;
	case Restrict;
	case None;
}