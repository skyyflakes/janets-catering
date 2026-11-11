<?php
/* Shared form fields for Add/Edit Food Item modal (food_menus.php) */
$courses = $courses ?? ['Appetizers','Main Courses','Desserts','Beverages','Sides'];
?>
<div class="row">
    <div class="col-md-7 mb-3">
        <label class="form-label">Item Name <span class="text-danger">*</span></label>
        <input type="text" name="item_name" class="form-control" required placeholder="e.g., Prime Ribeye Steak">
    </div>
    <div class="col-md-5 mb-3">
        <label class="form-label">Course <span class="text-danger">*</span></label>
        <select name="course" class="form-select" required>
            <?php foreach ($courses as $c): ?>
            <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Upgrade Price per Guest ($)</label>
        <input type="number" step="0.01" min="0" name="upgrade_price" class="form-control" value="0">
        <small class="text-muted">0 = Included in package</small>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Availability Note</label>
        <input type="text" name="availability_note" class="form-control" placeholder="e.g., In Gold/Platinum">
    </div>
</div>
<div class="mb-3">
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="2" placeholder="Short description of the dish"></textarea>
</div>
<div class="mb-2">
    <label class="form-label">Food Photo</label>
    <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" onchange="previewPhoto(this, this.closest('form').querySelector('.photo-preview').id)">
    <small class="text-muted">JPG, PNG, GIF or WebP, up to 5 MB. Choosing a new photo replaces the current one.</small>
    <img class="photo-preview" id="itemPreview_<?php echo uniqid(); ?>" style="display:none;" alt="Preview">
</div>
