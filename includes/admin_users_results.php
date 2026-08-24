<?php
/**
 * includes/admin_users_results.php — the User Management results region.
 *
 * Rendered two ways from admin_users.php, from the same variables:
 *   • inline, as part of the full page;
 *   • alone, when the page is asked for ?partial=1, so the browser can swap
 *     just this block in after a search or a filter change.
 *
 * That is the whole of the "search without reloading the page" behaviour: the
 * queries, the paging and the markup are unchanged and still server-rendered —
 * only the delivery differs. Nothing here may emit <head>, the sidebar, or a
 * modal, or the swapped-in copy would nest a second one inside the page.
 *
 * Expects in scope: $users, $view, $page, $totalPages, $pageOverrun, $rowFrom,
 * $rowTo, $matchTotal, $perPage, $pageQuery, plus the esc()/initials()/
 * avatarColor()/deptCls() helpers from admin_users.php.
 */
if (!isset($users) || !isset($pageQuery)) { return; }   // never reachable on its own
?>
    <?php if ($pageOverrun): ?>
    <div class="capnote">
      <i class="fas fa-circle-exclamation"></i>
      <span>Page <strong><?php echo number_format($page); ?></strong> is past the end of this list
      &mdash; there <?php echo $totalPages === 1 ? 'is' : 'are'; ?>
      <strong><?php echo number_format($totalPages); ?></strong>
      page<?php echo $totalPages !== 1 ? 's' : ''; ?>.
      <a href="<?php echo esc($pageQuery(1)); ?>">Back to the first page</a>.</span>
    </div>
    <?php endif; ?>

    <!-- ════ TABLE VIEW ════ -->
    <?php if ($view === 'table'): ?>
    <div class="panel" id="tableView">
      <div class="ph3">
        <h3><i class="fas fa-list-alt"></i> User Records</h3>
        <!-- Export, Add User and Invite Technician were repeated here from the
             page header a few hundred pixels above, so the same three actions
             appeared twice on one screen. They live in the header only. -->
      </div>
      <!-- No data-paginate here: this list is paged in SQL (see $perPage). The
           client-side paginator would slice the 50 rows the server already
           chose, leaving two stacked pagers disagreeing about "page 1". -->
      <!-- Nine columns of real content do not fit every desktop. The panel
           clips (overflow:hidden, for its rounded corners), so without this the
           Joined date and the whole Actions column are simply not reachable. -->
      <div class="tblwrap">
      <table class="tbl u-fixed" id="uTbl">
        <?php /* Nine columns sized by their content needed 1,608px inside a
                 1,102px panel, so Actions — the Edit and Delete buttons — sat
                 506px off the right edge, reachable only by scrolling a table
                 that gives no sign it scrolls. Percentages rather than rem: they
                 are relative to the table, so the row fits whatever width the
                 panel has instead of fitting one screen and clipping on the
                 next. Text that no longer fits ellipses, which the columns
                 already did. */ ?>
        <?php /* Reports and Tasks hold single digits, so their width is set
                 entirely by the header word above them, not by the data. They
                 need more than the numbers suggest; Email needs less than it
                 wants, and the full address is one click away in the row. */ ?>
        <colgroup>
          <col style="width:18%"><col style="width:17%"><col style="width:11%">
          <col style="width:14%"><col style="width:6%"><col style="width:8%">
          <col style="width:7%"><col style="width:9%"><col style="width:10%">
        </colgroup>
        <thead>
          <?php /* "Year" and "Tasks" rather than "Year Level" and "Active
                   Tasks": the headers are nowrap, so at these widths the long
                   pair ran into the column beside it. The short words say the
                   same thing over a column of numbers. */ ?>
          <tr>
            <th>User</th><th>Email</th><th class="nw">Standing</th><th>Department</th>
            <th class="c nw">Year</th>
            <th class="c">Reports</th><th class="c">Tasks</th><th class="c nw">Joined</th>
            <th class="c">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($users)): ?>
          <tr><td colspan="9"><div class="empty"><i class="fas fa-users-slash"></i>No users match the current filters.</div></td></tr>
          <?php else: foreach($users as $u):
            $init = initials($u['fullname']??'??');
            $avcol = avatarColor($u['role']??'');
            $dc = deptCls($u['department']??'');
          ?>
          <tr class="urow" data-user="<?php echo cardPayload($u);?>" tabindex="0" aria-label="Open user details">
            <td>
              <div class="tuser">
                <div class="tav" style="background:<?php echo $avcol;?>;"><?php echo $init;?></div>
                <div>
                  <div class="tname"><?php echo esc($u['fullname']??'—'); ?></div>
                  <div class="tuid"><?php echo esc($u['user_id']); ?></div>
                </div>
              </div>
            </td>
            <td class="temail"><?php echo esc($u['email']??'—'); ?></td>
            <td class="nw">
              <span class="bdg <?php echo roleCls($u['role']); ?>">
                <i class="<?php echo roleIco($u['role']); ?>" style="font-size:.6rem;margin-right:.18rem;"></i>
                <?php echo roleLbl($u['role']); ?>
              </span><?php echo typeBadge($u); ?>
            </td>
            <td>
              <?php if(!empty($u['department'])):?>
              <span class="dept-<?php echo $dc;?>">
                <?php if($dc==='itso') echo '<i class="fas fa-laptop-code"></i>';
                      elseif($dc==='pmo') echo '<i class="fas fa-building"></i>';
                      else echo '<i class="fas fa-building"></i>'; ?>
                <?php echo esc($u['department']); ?>
              </span>
              <?php else: ?><span style="color:var(--t4);font-size:.72rem;">—</span><?php endif; ?>
            </td>
            <td class="c nw" style="font-size:.74rem;color:var(--t2,#5B4636);">
              <?php echo !empty($u['year_level']) ? esc($u['year_level']) : '<span style="color:var(--t4);font-size:.72rem;">—</span>'; ?>
            </td>
            <td class="c num" style="color:var(--m3);">
              <?php echo (int)($u['report_count']??0); ?>
            </td>
            <td class="c num">
              <?php $at=(int)($u['active_tasks']??0); ?>
              <span style="color:<?php echo $at>3?'#DC2626':($at>1?'#D97706':'#16A34A');?>;"><?php echo $at; ?></span>
            </td>
            <td class="c nw" style="font-size:.72rem;color:var(--t3);">
              <?php echo !empty($u['created_at'])?date('M j, Y',strtotime($u['created_at'])):'—'; ?>
            </td>
            <td class="c">
              <div class="no-row-open" style="display:flex;gap:.25rem;justify-content:center;">
                <button type="button" class="btn bico bi-v" title="View Profile"
                  onclick="openProfile(rowData(this))">
                  <i class="fas fa-eye"></i>
                </button>
                <?php if(empty($u['is_directory'])): ?>
                <button type="button" class="btn bico bi-e" title="Edit User"
                  onclick="openEdit(rowData(this))">
                  <i class="fas fa-pen"></i>
                </button>
                <?php if($roleNeedsPassword($u['role'] ?? '')): ?>
                <button type="button" class="btn bico bi-k" title="Reset Password"
                  onclick="openReset('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
                  <i class="fas fa-key"></i>
                </button>
                <?php endif; ?>
                <?php if($u['user_id']!==$admin_id): ?>
                <button type="button" class="btn bico bi-d" title="Delete"
                  onclick="delUser('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
                  <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
                <?php else: ?>
                <span class="bdg" style="background:rgba(8,145,178,.12);color:#0891B2;font-size:.6rem;" title="Imported from the BEC directory — reporter, no login account"><i class="fas fa-address-book" style="font-size:.6rem;margin-right:.18rem;"></i>Directory</span>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <?php endif; ?>

    <!-- ════ GRID VIEW ════ -->
    <?php if ($view === 'grid'): ?>
    <div id="gridView">
      <div class="ugrid"><!-- paged in SQL, same as the table view -->
        <?php if(empty($users)): ?>
        <div style="grid-column:1/-1;"><div class="empty"><i class="fas fa-users-slash"></i>No users match the current filters.</div></div>
        <?php else: foreach($users as $i=>$u):
          $init = initials($u['fullname']??'??');
          $avcol = avatarColor($u['role']??'');
          $dc = deptCls($u['department']??'');
          $isDir = !empty($u['is_directory']);
        ?>
        <div class="ucard role-<?php echo esc($u['role']??'reporter');?>"
          data-user="<?php echo cardPayload($u);?>"
          style="animation-delay:<?php echo min($i,25)*.04;?>s;">
          <div class="uc-top">
            <div class="uc-av" style="background:<?php echo $avcol;?>;"><?php echo $init;?></div>
            <?php if($isDir): ?><span class="uc-status"><span class="bdg" style="background:rgba(8,145,178,.12);color:#0891B2;font-size:.6rem;"><i class="fas fa-address-book" style="font-size:.55rem;margin-right:.15rem;"></i>Directory</span></span><?php endif; ?>
          </div>
          <div class="uc-name"><?php echo esc($u['fullname']??'—');?></div>
          <div class="uc-id"><?php echo esc($u['user_id']);?></div>
          <div class="uc-email"><i class="fas fa-envelope" style="font-size:.62rem;color:var(--t3);flex-shrink:0;"></i><?php echo esc($u['email']??'—');?></div>
          <div class="uc-meta">
            <span class="bdg <?php echo roleCls($u['role']);?>">
              <i class="<?php echo roleIco($u['role']);?>" style="font-size:.6rem;margin-right:.15rem;"></i>
              <?php echo roleLbl($u['role']);?>
            </span><?php echo typeBadge($u); ?>
            <?php if(!empty($u['department'])):?>
            <span class="dept-<?php echo $dc;?>" style="font-size:.6rem;padding:.15rem .48rem;">
              <?php echo esc($u['department']);?>
            </span>
            <?php endif;?>
          </div>
          <div class="uc-stats">
            <div class="uc-stat">
              <div class="uc-stat-n" style="color:var(--m3);"><?php echo (int)($u['report_count']??0);?></div>
              <div class="uc-stat-l">Reports</div>
            </div>
            <div class="uc-stat">
              <?php $at=(int)($u['active_tasks']??0);?>
              <div class="uc-stat-n" style="color:<?php echo $at>3?'#DC2626':($at>1?'#D97706':'#16A34A');?>;"><?php echo $at;?></div>
              <div class="uc-stat-l">Active Tasks</div>
            </div>
          </div>
          <div class="uc-acts">
            <button type="button" class="btn btn-ghost btn-sm" style="flex:1;justify-content:center;"
              onclick="openProfile(rowData(this))">
              <i class="fas fa-eye"></i> View
            </button>
            <?php if(empty($u['is_directory'])): ?>
            <button type="button" class="btn btn-gold btn-sm" title="Edit User"
              onclick="openEdit(rowData(this))">
              <i class="fas fa-pen"></i>
            </button>
            <?php if($roleNeedsPassword($u['role'] ?? '')): ?>
            <button type="button" class="btn btn-ghost btn-sm" title="Reset Password"
              onclick="openReset('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
              <i class="fas fa-key"></i>
            </button>
            <?php endif; ?>
            <?php if($u['user_id']!==$admin_id):?>
            <button type="button" class="btn btn-red btn-sm" title="Delete"
              onclick="delUser('<?php echo esc($u['user_id']);?>','<?php echo esc($u['fullname']??'');?>')">
              <i class="fas fa-trash"></i>
            </button>
            <?php endif;?>
            <?php else: ?>
            <span class="bdg" style="flex:1;justify-content:center;background:rgba(8,145,178,.1);color:#0891B2;font-size:.62rem;" title="Imported from the BEC directory — reporter, no login account to edit"><i class="fas fa-address-book" style="font-size:.6rem;margin-right:.2rem;"></i>Directory record</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; endif;?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <!-- Paging is done in SQL: each page is its own LIMIT/OFFSET query, so the
         browser never receives the rows it isn't showing. -->
    <nav class="pager" id="userPager" aria-label="User list pages">
      <span class="pager-count">
        <?php if (count($users) > 0): ?>
          <?php echo number_format($rowFrom); ?>&ndash;<?php echo number_format($rowTo); ?>
        <?php else: ?>
          No rows on this page &mdash;
        <?php endif; ?>
        of <strong><?php echo number_format($matchTotal); ?></strong>
      </span>
      <span class="pager-btns">
        <?php if ($page > 1): ?>
          <a class="pgb" href="<?php echo esc($pageQuery($page - 1)); ?>" rel="prev"><i class="fas fa-chevron-left"></i> Previous</a>
        <?php else: ?>
          <span class="pgb off"><i class="fas fa-chevron-left"></i> Previous</span>
        <?php endif; ?>

        <?php
        // A window around the current page, so 36 pages don't render 36 links.
        $from = max(1, $page - 2);
        $to   = min($totalPages, $page + 2);
        if ($from > 1): ?>
          <a class="pgb" href="<?php echo esc($pageQuery(1)); ?>">1</a>
          <?php if ($from > 2): ?><span class="pg-gap">&hellip;</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $from; $i <= $to; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="pgb on" aria-current="page"><?php echo $i; ?></span>
          <?php else: ?>
            <a class="pgb" href="<?php echo esc($pageQuery($i)); ?>"><?php echo $i; ?></a>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($to < $totalPages): ?>
          <?php if ($to < $totalPages - 1): ?><span class="pg-gap">&hellip;</span><?php endif; ?>
          <a class="pgb" href="<?php echo esc($pageQuery($totalPages)); ?>"><?php echo number_format($totalPages); ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
          <a class="pgb" href="<?php echo esc($pageQuery($page + 1)); ?>" rel="next">Next <i class="fas fa-chevron-right"></i></a>
        <?php else: ?>
          <span class="pgb off">Next <i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
      </span>
    </nav>
    <?php endif; ?>
