<?php
/**
 * The design tokens becca_widget.php reads.
 *
 * The widget was lifted out of student_index.php and still expects that page's
 * long token names (--maroon, --gold, --ink, --surface, --border, ...) with no
 * fallback values anywhere in its CSS. index.php and student_index.php happen to
 * declare them, so it looked fine there; track_report.php and public_reports.php
 * name the same colours --m, --g, --k, --s, --b, so every var() in the widget
 * resolved to nothing and the assistant rendered as an unstyled lump.
 *
 * Rather than rename tokens across pages, or teach the widget a second naming
 * scheme, this declares the names the widget asks for. The values are the ones
 * index.php already uses, so nothing shifts on the pages that had them.
 *
 * Require this immediately before includes/becca_widget.php on any page whose
 * palette does not already use the long names.
 */
?>
<style>
  :root{
    --maroon:#7B1D1D; --maroon-d:#4A0E0E; --maroon-dd:#2D0505;
    --maroon-soft:rgba(123,29,29,.08);
    --gold:#C9960C; --gold-bg:#FFFBEF;
    --ink:#1C1008; --ink2:#5C3838; --ink3:#755B4E;
    --surface:#FFFFFF; --border:#E8DDD0;
  }
</style>
