<?php
/* Shared form fields for Add/Edit Package modal (food_menus.php). Display Order is only for rearranging existing packages. */
$pkg_form_edit = $pkg_form_edit ?? false;
?>
<div class="row">
    <div class="col-md-7 mb-3">
        <label class="form-label">Package Name <span class="text-danger">*</span></label>
        <input type="text" name="package_name" class="form-control" required placeholder="e.g., Gold Premium">
    </div>
    <div class="col-md-5 mb-3">
        <label class="form-label">Tier</label>
        <select name="tier" class="form-select">
            <option value="Silver">Silver</option>
            <option value="Gold">Gold</option>
            <option value="Platinum">Platinum</option>
            <option value="Standard">Standard</option>
        </select>
    </div>
</div>
<div class="row">
    <div class="<?php echo $pkg_form_edit ? 'col-md-6' : 'col-12'; ?> mb-3">
        <label class="form-label">Price per Guest (₱) <span class="text-danger">*</span></label>
        <input type="number" step="0.01" min="0" name="price_per_guest" class="form-control" required placeholder="750.00">
    </div>
    <?php if ($pkg_form_edit): ?>
    <div class="col-md-6 mb-3">
        <label class="form-label">Display Order</label>
        <input type="number" name="sort_order" class="form-control" value="0">
    </div>
    <?php endif; ?>
</div>
<div class="mb-3">
    <label class="form-label">Pricing Note</label>
    <input type="text" name="pricing_note" class="form-control" maxlength="150" placeholder="e.g., ₱750-950 per head, depending on pax">
</div>
<div class="mb-3">
    <label class="form-label">Inclusions</label>
    <textarea name="inclusions" class="form-control" rows="8" placeholder="One inclusion per line, for example:&#10;4-hour all-in service&#10;Fresh garden salad&#10;Lights and sounds"></textarea>
    <small class="text-muted">Type one inclusion per line. Each line is saved as its own record and shown as a bullet.</small>
</div>
<div class="mb-3">
    <label class="form-label d-block">Available For</label>
    <div class="d-flex flex-wrap gap-3">
        <?php foreach ($all_event_types ?? [] as $et): ?>
        <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="event_types[]" value="<?php echo (int)$et['event_type_id']; ?>"><?php echo htmlspecialchars($et['event_type_name']); ?></label>
        <?php endforeach; ?>
    </div>
    <small class="text-muted">Leave all unticked to offer this package for every event type.</small>
</div>
<div class="mb-2">
    <label class="form-label">Package Photo</label>
    <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewPhoto(this, this.closest('form').querySelector('.photo-preview').id)">
    <small class="text-muted">JPG, PNG, GIF, WebP</small>
    <img class="photo-preview" id="pkgPreview_<?php echo uniqid(); ?>" style="display:none;" alt="Preview">
</div>
