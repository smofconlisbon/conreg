<?php

namespace Drupal\conreg_planz;

/**
 * Options for the Badge ID field.
 */
enum BadgeIdSource: string {
  case MemberID = "mid";
  case MemberNumber = "mno";
}
